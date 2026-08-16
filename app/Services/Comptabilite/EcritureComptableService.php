<?php

namespace App\Services\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\EvenementComptable;
use App\Enums\StatutPieceComptable;
use App\Exceptions\Comptabilite\EcritureDesequilibreeException;
use App\Exceptions\Comptabilite\PeriodeComptableClotureeException;
use App\Models\EcritureComptable;
use App\Models\PieceComptable;
use App\Models\TiersComptable;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée unique du moteur comptable. Ne connaît AUCUNE règle métier
 * (pas de "commission propriétaire", pas de numéro de compte) — il reçoit des
 * lignes déjà composées par un service spécialisé (FicheComptabilisationService,
 * DepenseComptabilisationService...), résout les comptes via CompteMappingResolver,
 * garantit idempotence + équilibre + numérotation + verrou de période, et
 * persiste transactionnellement.
 */
class EcritureComptableService
{
    public function __construct(
        private readonly CompteMappingResolver $mappings,
        private readonly PeriodeComptableResolver $periodes,
        private readonly PieceNumerotationService $numerotation,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  list<array{role:string,sens:string,montant:float,moyen_paiement?:?string,tiers_type?:?string,tiers_model?:?Model,libelle?:?string}>  $lignes
     */
    public function comptabiliser(
        EvenementComptable $evenement,
        Model $source,
        string $organizationId,
        Carbon $dateComptable,
        string $libelle,
        array $lignes,
        ?string $siteId = null,
        ?string $createdBy = null,
        bool $ignorerVerrouPeriode = false,
    ): PieceComptable {
        if (empty($lignes)) {
            throw new \InvalidArgumentException('comptabiliser() requiert au moins une ligne.');
        }

        // ── Idempotence (garde applicative rapide, avant tout travail) ──────────
        $existante = $this->pieceExistantePour($organizationId, $source, $evenement);
        if ($existante) {
            return $existante;
        }

        // ── Résolution des comptes/journal AVANT la transaction (lecture seule) ─
        $resolues = [];
        $journal = null;
        foreach ($lignes as $i => $ligne) {
            // Cas particulier (ex: charge d'une dépense dont le compte dépend du
            // DepenseType et non d'un mapping générique par événement) : le compte
            // est déjà résolu par l'appelant, on ne passe pas par CompteMappingResolver
            // pour CETTE ligne — mais le journal doit toujours venir d'une ligne mappée.
            if (! empty($ligne['compte_comptable_id'])) {
                $compteId = $ligne['compte_comptable_id'];
                $tiersComptable = null;
            } else {
                $mapping = $this->mappings->resolve($organizationId, $evenement, $ligne['role'], $ligne['moyen_paiement'] ?? null);
                $journal ??= $mapping->journal;
                $compteId = $mapping->compte_comptable_id;

                $tiersComptable = null;
                if (! empty($ligne['tiers_model'])) {
                    $tiersComptable = TiersComptable::resoudrePour(
                        $organizationId,
                        $ligne['tiers_type'] ?? 'inconnu',
                        $ligne['tiers_model'],
                        $compteId,
                    );
                }
            }

            $montant = round((float) $ligne['montant'], 2);
            $debit = $ligne['sens'] === 'debit' ? $montant : 0.0;
            $credit = $ligne['sens'] === 'credit' ? $montant : 0.0;

            if (($debit > 0) === ($credit > 0)) {
                throw EcritureDesequilibreeException::pourLigneInvalide($i, $debit, $credit);
            }

            $resolues[] = [
                'compte_comptable_id' => $compteId,
                'tiers_comptable_id' => $tiersComptable?->id,
                'libelle' => $ligne['libelle'] ?? $libelle,
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        if (! $journal) {
            throw new \RuntimeException("Aucun journal résolu pour l'événement « {$evenement->value} ».");
        }

        $totalDebit = round(array_sum(array_column($resolues, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($resolues, 'credit')), 2);
        if (abs($totalDebit - $totalCredit) > 0.01) {
            throw EcritureDesequilibreeException::pourEcart($totalDebit, $totalCredit);
        }

        try {
            return DB::transaction(function () use (
                $organizationId, $evenement, $source, $dateComptable, $libelle,
                $resolues, $journal, $siteId, $createdBy, $ignorerVerrouPeriode,
            ) {
                // Re-vérifie sous transaction (fenêtre de course réduite ; le filet de
                // sécurité final reste la contrainte unique en base, cf. catch ci-dessous).
                $existante = $this->pieceExistantePour($organizationId, $source, $evenement);
                if ($existante) {
                    return $existante;
                }

                $periode = $this->periodes->resolveOrCreate($organizationId, $dateComptable);

                if ($periode->isCloturee() && ! $ignorerVerrouPeriode) {
                    throw PeriodeComptableClotureeException::pourDate($organizationId, $dateComptable->toDateString());
                }

                $numero = $this->numerotation->next($journal, $periode->exercice);

                $piece = PieceComptable::create([
                    'organization_id' => $organizationId,
                    'journal_comptable_id' => $journal->id,
                    'exercice_comptable_id' => $periode->exercice_comptable_id,
                    'periode_comptable_id' => $periode->id,
                    'numero' => $numero,
                    'date_piece' => $dateComptable->toDateString(),
                    'libelle' => $libelle,
                    'source_type' => $source->getMorphClass(),
                    'source_id' => $source->getKey(),
                    'type_evenement' => $evenement->value,
                    'statut' => StatutPieceComptable::VALIDEE->value,
                    'created_by' => $createdBy,
                ]);

                foreach ($resolues as $ligne) {
                    EcritureComptable::create($ligne + [
                        'piece_comptable_id' => $piece->id,
                        'site_id' => $siteId,
                    ]);
                }

                $this->audit->record($piece, AuditEvent::CREATED, null, null, [
                    'numero' => $numero,
                    'type_evenement' => $evenement->value,
                    'source_type' => $source->getMorphClass(),
                    'source_id' => $source->getKey(),
                ], ['module' => 'comptabilite_generale']);

                return $piece;
            });
        } catch (QueryException $e) {
            if ($this->estConflitIdempotence($e)) {
                return $this->pieceExistantePour($organizationId, $source, $evenement)
                    ?? throw $e;
            }

            throw $e;
        }
    }

    /**
     * Contrepasse une pièce validée (débit/crédit inversés, même montants,
     * mêmes comptes/tiers) — jamais de suppression ni de correction en place
     * d'une pièce déjà validée (règle #29 de la spec).
     */
    public function contrepasser(PieceComptable $piece, string $motif, ?string $createdBy = null): PieceComptable
    {
        if (! $piece->isValidee()) {
            throw new \RuntimeException("Seule une pièce validée peut être contrepassée (pièce {$piece->numero} : statut {$piece->statut->value}).");
        }

        return DB::transaction(function () use ($piece, $motif, $createdBy) {
            // La contrepassation se poste dans la période COURANTE (aujourd'hui), pas
            // forcément celle de la pièce d'origine qui peut déjà être clôturée — c'est
            // une correction faite maintenant, pas une réécriture du passé.
            $periode = $this->periodes->resolveOrCreate($piece->organization_id, now());
            $numero = $this->numerotation->next($piece->journal, $periode->exercice);

            $extourne = PieceComptable::create([
                'organization_id' => $piece->organization_id,
                'journal_comptable_id' => $piece->journal_comptable_id,
                'exercice_comptable_id' => $periode->exercice_comptable_id,
                'periode_comptable_id' => $periode->id,
                'numero' => $numero,
                'date_piece' => now()->toDateString(),
                'libelle' => 'Contrepassation '.$piece->numero.' — '.$motif,
                'source_type' => $piece->source_type,
                'source_id' => $piece->source_id,
                // Court et unique par pièce d'origine (contrainte type_evenement <= 100
                // caractères) : "contrepassation_de_{ulid}" suffit à garantir l'unicité
                // requise par l'index d'idempotence sans dépendre de la longueur de
                // l'événement d'origine.
                'type_evenement' => 'contrepassation_de_'.$piece->id,
                'statut' => StatutPieceComptable::VALIDEE->value,
                'piece_origine_id' => $piece->id,
                'created_by' => $createdBy,
            ]);

            foreach ($piece->lignes as $ligne) {
                EcritureComptable::create([
                    'piece_comptable_id' => $extourne->id,
                    'compte_comptable_id' => $ligne->compte_comptable_id,
                    'tiers_comptable_id' => $ligne->tiers_comptable_id,
                    'site_id' => $ligne->site_id,
                    'libelle' => $ligne->libelle,
                    'debit' => $ligne->credit,
                    'credit' => $ligne->debit,
                ]);
            }

            $piece->update(['statut' => StatutPieceComptable::CONTREPASSEE->value]);

            $this->audit->record($piece, AuditEvent::STATUS_CHANGED, null, null, [
                'motif' => $motif,
                'contrepassation_numero' => $numero,
            ], ['module' => 'comptabilite_generale']);

            return $extourne;
        });
    }

    /**
     * Vérifie l'idempotence d'un (organisation, source, événement) sans rien écrire —
     * même clé que la contrainte unique compta_pieces_idempotency_unique. Public pour
     * être réutilisable en lecture seule par un rattrapage (dry-run : distinguer "déjà
     * comptabilisé" de "à comptabiliser" sans dupliquer cette requête ailleurs).
     */
    public function pieceExistantePour(string $organizationId, Model $source, EvenementComptable $evenement): ?PieceComptable
    {
        return PieceComptable::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('type_evenement', $evenement->value)
            ->first();
    }

    private function estConflitIdempotence(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'pieces_idempotency_unique');
    }
}
