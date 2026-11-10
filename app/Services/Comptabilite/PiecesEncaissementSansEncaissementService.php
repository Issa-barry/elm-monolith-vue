<?php

namespace App\Services\Comptabilite;

use App\Enums\EvenementComptable;
use App\Enums\StatutFactureVente;
use App\Enums\StatutPieceComptable;
use App\Models\EcritureComptable;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\PieceComptable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pièces de caisse dont l'encaissement n'existe plus (incident VTE-081126-134 du 08/11/2026, ADR 0062) :
 * un deadlock masqué avait annulé l'encaissement mais laissé sa pièce validée, ce qui gonflait le solde
 * de la caisse. Une pièce n'est contrepassée que si TOUT confirme qu'elle ne correspond à aucun argent
 * reçu ; sinon elle est signalée « à examiner » et jamais touchée.
 */
class PiecesEncaissementSansEncaissementService
{
    public const MOTIF = 'Incident 08/11 : encaissement annulé par la base de données, pièce sans encaissement (ADR 0062)';

    public const CONTREPASSER = 'contrepasser';

    public const A_EXAMINER = 'a_examiner';

    private const PREFIXE_LIBELLE = 'Encaissement facture ';

    public function __construct(private readonly EcritureComptableService $ecritures) {}

    /**
     * Rapport prévisionnel, sans rien écrire.
     *
     * @return list<array<string, mixed>>
     */
    public function analyser(): array
    {
        return PieceComptable::query()
            ->where('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)
            ->where('statut', StatutPieceComptable::VALIDEE->value)
            ->where('source_type', (new EncaissementVente)->getMorphClass())
            ->whereNotExists(fn ($q) => $q->from('encaissements_ventes as e')->whereColumn('e.id', 'compta_pieces.source_id'))
            ->with('lignes.compte:id,numero,libelle')
            ->orderBy('created_at')
            ->get()
            ->map(fn (PieceComptable $piece) => $this->examiner($piece))
            ->all();
    }

    /**
     * Contrepasse les pièces dont l'analyse confirme l'absence d'argent reçu, une par une, dans une
     * transaction qui revérifie tout sous verrou. Rejouable : une pièce contrepassée n'est plus validée.
     *
     * @return array{contrepassees: list<array<string, mixed>>, a_examiner: list<array<string, mixed>>, echecs: list<array<string, mixed>>}
     */
    public function regulariser(): array
    {
        $bilan = ['contrepassees' => [], 'a_examiner' => [], 'echecs' => []];

        foreach ($this->analyser() as $constat) {
            if ($constat['decision'] !== self::CONTREPASSER) {
                $bilan['a_examiner'][] = $constat;

                continue;
            }

            try {
                $contrepassation = DB::transaction(function () use ($constat) {
                    $piece = PieceComptable::query()->whereKey($constat['piece_id'])->lockForUpdate()->first();
                    $actuel = $piece && $piece->isValidee() ? $this->examiner($piece->load('lignes.compte:id,numero,libelle')) : null;
                    if ($actuel === null || $actuel['decision'] !== self::CONTREPASSER) {
                        return null;
                    }

                    return $this->ecritures->contrepasser($piece, self::MOTIF);
                });
            } catch (Throwable $e) {
                $bilan['echecs'][] = [...$constat, 'erreur' => $e->getMessage()];

                continue;
            }

            if ($contrepassation) {
                $bilan['contrepassees'][] = [...$constat, 'contrepassation' => $contrepassation->numero];
            }
        }

        return $bilan;
    }

    /** @return array<string, mixed> */
    private function examiner(PieceComptable $piece): array
    {
        $debits = $piece->lignes->filter(fn (EcritureComptable $l) => (float) $l->debit > 0)->values();
        $credits = $piece->lignes->filter(fn (EcritureComptable $l) => (float) $l->credit > 0)->values();
        $debit = $debits->first();
        $credit = $credits->first();

        $facture = str_starts_with((string) $piece->libelle, self::PREFIXE_LIBELLE)
            ? FactureVente::withTrashed()
                ->where('organization_id', $piece->organization_id)
                ->where('reference', substr((string) $piece->libelle, strlen(self::PREFIXE_LIBELLE)))
                ->with('encaissements')
                ->first()
            : null;

        $constat = [
            'piece_id' => $piece->id,
            'numero' => $piece->numero,
            'date_piece' => $piece->date_piece?->toDateString(),
            'libelle' => $piece->libelle,
            'facture' => $facture?->reference,
            'statut_facture' => $facture?->statut_facture?->value,
            'montant_facture' => $facture ? round((float) $facture->montant_net, 2) : null,
            'encaisse_reel' => $facture ? round((float) $facture->encaissements->sum('montant') - $facture->montantRembourse(), 2) : null,
            'compte_debit' => $debit?->compte?->numero,
            'caisse' => $debit?->compte?->libelle,
            'compte_debit_id' => $debit?->compte_comptable_id,
            'debit' => round((float) $debits->sum('debit'), 2),
            'compte_credit' => $credit?->compte?->numero,
            'credit' => round((float) $credits->sum('credit'), 2),
        ];

        return [...$constat, ...$this->decision($piece, $constat, $facture, $debits->count(), $credits->count())];
    }

    /**
     * @param  array<string, mixed>  $constat
     * @return array{decision: string, raison: string}
     */
    private function decision(PieceComptable $piece, array $constat, ?FactureVente $facture, int $nbDebits, int $nbCredits): array
    {
        $examiner = fn (string $raison) => ['decision' => self::A_EXAMINER, 'raison' => $raison];

        if ($piece->lignes->count() !== 2 || $nbDebits !== 1 || $nbCredits !== 1
            || abs($constat['debit'] - $constat['credit']) > 0.004 || $constat['debit'] <= 0
            || ! str_starts_with((string) $constat['compte_debit'], '5')
            || ! str_starts_with((string) $constat['compte_credit'], '411')) {
            return $examiner('Écritures inattendues : seule une pièce « trésorerie au débit / client 411 au crédit » est régularisée.');
        }

        $pourCompte = PieceComptable::query()
            ->where('organization_id', $piece->organization_id)
            ->where('source_id', $piece->source_id)
            ->where('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_POUR_COMPTE->value)
            ->exists();
        if ($pourCompte) {
            return $examiner('Encaissement reçu pour une autre agence : deux pièces liées, à régulariser ensemble par la comptabilité.');
        }

        if (! $facture || $facture->trashed()) {
            return $examiner('Facture introuvable ou supprimée.');
        }

        if ($facture->statut_facture === StatutFactureVente::ANNULEE) {
            return $facture->encaissements->isEmpty()
                ? ['decision' => self::CONTREPASSER, 'raison' => 'Facture annulée sans aucun encaissement : la pièce ne correspond à aucun argent reçu.']
                : $examiner('Facture annulée qui porte encore des encaissements.');
        }

        if ($constat['montant_facture'] > $constat['encaisse_reel'] + 0.004) {
            return $examiner("La facture n'est pas couverte par ses encaissements : l'argent a peut-être été reçu, à vérifier avec l'agent.");
        }

        $sansPiece = $facture->encaissements->contains(fn (EncaissementVente $e) => ! PieceComptable::query()
            ->where('organization_id', $piece->organization_id)
            ->where('source_type', $e->getMorphClass())
            ->where('source_id', $e->id)
            ->where('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)
            ->where('statut', StatutPieceComptable::VALIDEE->value)
            ->exists());
        if ($sansPiece) {
            return $examiner("Un encaissement réel de la facture n'a pas sa propre pièce validée.");
        }

        return ['decision' => self::CONTREPASSER, 'raison' => 'Facture soldée par ses encaissements réels, chacun avec sa pièce : cette pièce est en double.'];
    }
}
