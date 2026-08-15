<?php

namespace App\Console\Commands;

use App\Enums\CategorieDepense;
use App\Enums\StatutDepense;
use App\Enums\StatutFactureVente;
use App\Enums\StatutPeriodePaiement;
use App\Models\Depense;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PieceComptable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Contrôle de cohérence métier ↔ comptabilité, à exécuter après un rattrapage
 * (ComptabiliteRattrapageCommand) ou périodiquement en exploitation. Un
 * rattrapage "terminé" au sens de cette commande = aucune ligne dans les
 * colonnes "non comptabilisé(e)s" et "pièces déséquilibrées" — jamais un
 * simple "Terminé" sans preuve.
 *
 * Ne modifie jamais rien : lecture seule.
 */
class ComptabiliteAuditCommand extends Command
{
    protected $signature = 'comptabilite:auditer {--organization=* : ID, code ou slug d\'organisation (répétable) ; toutes si omis}';

    protected $description = 'Contrôle de cohérence entre les opérations métier et compta_pieces/compta_ecritures (équilibre, éligibles vs comptabilisés).';

    public function handle(): int
    {
        $organizations = $this->resolveOrganizations();
        if ($organizations === null) {
            return self::FAILURE;
        }
        if ($organizations->isEmpty()) {
            $this->error('Aucune organisation trouvée.');

            return self::FAILURE;
        }

        $anomalieGlobale = false;

        foreach ($organizations as $organization) {
            $this->newLine();
            $this->line("<fg=cyan>▸ {$organization->name}</> ({$organization->id})");

            $anomalieGlobale = $this->auditerOrganisation($organization) || $anomalieGlobale;
        }

        $this->newLine();
        if ($anomalieGlobale) {
            $this->error('Anomalie(s) détectée(s) — voir le détail ci-dessus. `php artisan comptabilite:rattraper --organization=... --dry-run` pour investiguer.');

            return self::FAILURE;
        }

        $this->info('Aucune anomalie détectée : métier et comptabilité sont cohérents.');

        return self::SUCCESS;
    }

    private function auditerOrganisation(Organization $org): bool
    {
        $anomalie = false;

        // ── Volumétrie + équilibre global ────────────────────────────────────
        $nbPieces = PieceComptable::where('organization_id', $org->id)->count();
        $totaux = DB::table('compta_ecritures')
            ->join('compta_pieces', 'compta_pieces.id', '=', 'compta_ecritures.piece_comptable_id')
            ->where('compta_pieces.organization_id', $org->id)
            ->selectRaw('COUNT(*) as nb_ecritures, COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        $ecart = round((float) $totaux->total_debit - (float) $totaux->total_credit, 2);

        $this->table(['Pièces comptables', 'Écritures', 'Total débit', 'Total crédit', 'Écart'], [[
            $nbPieces,
            $totaux->nb_ecritures,
            number_format((float) $totaux->total_debit, 2, ',', ' '),
            number_format((float) $totaux->total_credit, 2, ',', ' '),
            $ecart == 0.0 ? '0' : "⚠ {$ecart}",
        ]]);

        if (abs($ecart) > 0.01) {
            $this->error('Total débit ≠ total crédit sur l\'ensemble de l\'organisation.');
            $anomalie = true;
        }

        // ── Pièces individuellement déséquilibrées (garde-fou, ne devrait jamais
        //    arriver vu EcritureComptableService::comptabiliser(), mais vérifié
        //    indépendamment plutôt que supposé) ──────────────────────────────
        $piecesDesequilibrees = DB::table('compta_ecritures')
            ->join('compta_pieces', 'compta_pieces.id', '=', 'compta_ecritures.piece_comptable_id')
            ->where('compta_pieces.organization_id', $org->id)
            ->groupBy('compta_pieces.id', 'compta_pieces.numero')
            ->havingRaw('ROUND(SUM(debit) - SUM(credit), 2) <> 0')
            ->pluck('compta_pieces.numero');

        if ($piecesDesequilibrees->isNotEmpty()) {
            $this->error('Pièce(s) déséquilibrée(s) : '.$piecesDesequilibrees->implode(', '));
            $anomalie = true;
        }

        // ── Éligibles vs comptabilisés, par domaine ──────────────────────────
        $lignes = [];

        $lignes[] = $this->ligneDomaine(
            'Dépenses',
            Depense::where('organization_id', $org->id)
                ->where('statut', StatutDepense::VALIDE->value)
                ->whereHas('depenseType', fn (Builder $q) => $q->where('categorie', '!=', CategorieDepense::EMPLOYE->value)),
            $org->id, Depense::class
        );

        $lignes[] = $this->ligneDomaine(
            'Fiches de commission',
            PaiementFiche::where('organization_id', $org->id)
                ->whereIn('beneficiaire_type', ['proprietaire', 'livreur'])
                ->whereHas('periode', fn (Builder $q) => $q->whereIn('statut', [
                    StatutPeriodePaiement::VALIDEE->value,
                    StatutPeriodePaiement::CLOTUREE->value,
                ])),
            $org->id, PaiementFiche::class
        );

        $lignes[] = $this->ligneDomaine(
            'Paiements de fiche',
            PaiementFichePaiement::where('organization_id', $org->id),
            $org->id, PaiementFichePaiement::class
        );

        $lignes[] = $this->ligneDomaine(
            'Ventes facturées',
            FactureVente::where('organization_id', $org->id)
                ->whereIn('statut_facture', [
                    StatutFactureVente::IMPAYEE->value,
                    StatutFactureVente::PARTIEL->value,
                    StatutFactureVente::PAYEE->value,
                ]),
            $org->id, FactureVente::class
        );

        $lignes[] = $this->ligneDomaine(
            'Encaissements clients',
            EncaissementVente::whereHas('facture', fn (Builder $q) => $q->where('organization_id', $org->id)),
            $org->id, EncaissementVente::class
        );

        $this->table(['Domaine', 'Éligibles', 'Comptabilisés', 'Non comptabilisés'], $lignes);

        foreach ($lignes as $ligne) {
            if ($ligne[3] > 0) {
                $anomalie = true;
            }
        }

        return $anomalie;
    }

    /**
     * Compare le nombre d'enregistrements éligibles d'un domaine au nombre de
     * pièces comptables déjà créées pour ce type de source — une seule requête de
     * comparaison (source_type + source_id), sans dupliquer la logique de
     * résolution d'événement (une seule pièce possible par source de toute façon,
     * cf. compta_pieces_idempotency_unique).
     *
     * @param  Builder<Model>  $eligibles
     * @param  class-string  $sourceType
     */
    private function ligneDomaine(string $label, Builder $eligibles, string $organizationId, string $sourceType): array
    {
        $idsComptabilises = PieceComptable::where('organization_id', $organizationId)
            ->where('source_type', $sourceType)
            ->pluck('source_id');

        $totalEligibles = (clone $eligibles)->count();
        $comptabilises = (clone $eligibles)->whereIn($eligibles->getModel()->getKeyName(), $idsComptabilises)->count();

        return [$label, $totalEligibles, $comptabilises, $totalEligibles - $comptabilises];
    }

    private function resolveOrganizations(): ?Collection
    {
        $identifiants = $this->option('organization');
        if (empty($identifiants)) {
            return Organization::query()->get();
        }

        $organizations = Organization::query()
            ->where(function (Builder $q) use ($identifiants) {
                $q->whereIn('id', $identifiants)
                    ->orWhereIn('code', $identifiants)
                    ->orWhereIn('slug', $identifiants);
            })
            ->get();

        $trouves = $organizations->flatMap(fn (Organization $o) => array_filter([$o->id, $o->code, $o->slug]));
        $manquants = array_diff($identifiants, $trouves->all());
        if (! empty($manquants)) {
            $this->error('Organisation(s) introuvable(s) : '.implode(', ', $manquants));

            return null;
        }

        return $organizations;
    }
}
