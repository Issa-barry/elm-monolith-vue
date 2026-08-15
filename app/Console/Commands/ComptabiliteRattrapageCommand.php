<?php

namespace App\Console\Commands;

use App\Enums\CategorieDepense;
use App\Enums\EvenementComptable;
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
use App\Services\Comptabilite\DepenseComptabilisationService;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Comptabilite\FicheComptabilisationService;
use App\Services\Comptabilite\VenteComptabilisationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rattrape la comptabilisation des opérations métier historiques éligibles —
 * nécessaire car les tables `depenses`/`commandes_ventes`/... existent toutes
 * depuis bien avant le module `compta_*` (12/08/2026, cf. docs/data-dictionary-compta.md) :
 * aucune donnée créée avant cette date n'a jamais pu être comptabilisée
 * automatiquement, quel que soit le bon fonctionnement du code actuel.
 *
 * Idempotent : réutilise EXACTEMENT le même mécanisme que le flux temps réel
 * (EcritureComptableService::pieceExistantePour(), adossé à la contrainte unique
 * compta_pieces_idempotency_unique) — aucun système de suivi parallèle. Relancer
 * cette commande plusieurs fois sur les mêmes données ne crée jamais de doublon.
 *
 * Ne recrée/modifie AUCUNE donnée métier : lit les enregistrements déjà existants
 * et rejoue les mêmes services de comptabilisation que le flux temps réel (mêmes
 * dates métier, jamais la date du jour).
 */
class ComptabiliteRattrapageCommand extends Command
{
    protected $signature = 'comptabilite:rattraper
        {--organization=* : ID, code ou slug d\'organisation (répétable) ; toutes si omis}
        {--type=* : depense,fiche,paiement-fiche,vente,encaissement (répétable) ; tous si omis}
        {--depuis= : date de début (YYYY-MM-DD), filtre sur la date métier}
        {--jusqua= : date de fin (YYYY-MM-DD), filtre sur la date métier}
        {--dry-run : simule sans rien écrire en base}';

    protected $description = 'Rattrape la comptabilisation des dépenses/fiches/paiements/ventes/encaissements historiques éligibles. Idempotent.';

    private const TYPES_VALIDES = ['depense', 'fiche', 'paiement-fiche', 'vente', 'encaissement'];

    public function handle(
        DepenseComptabilisationService $depenseService,
        FicheComptabilisationService $ficheService,
        VenteComptabilisationService $venteService,
        EcritureComptableService $ecritures,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $types = $this->resolveTypes();
        if ($types === null) {
            return self::FAILURE;
        }

        $organizations = $this->resolveOrganizations();
        if ($organizations === null) {
            return self::FAILURE;
        }
        if ($organizations->isEmpty()) {
            $this->error('Aucune organisation trouvée.');

            return self::FAILURE;
        }

        [$depuis, $jusqua] = $this->resolveDates();

        $this->info($dryRun
            ? '── Rattrapage comptable — DRY-RUN (rien ne sera écrit) ──'
            : '── Rattrapage comptable — exécution réelle ──');

        $totalErreurs = 0;

        foreach ($organizations as $organization) {
            $this->newLine();
            $this->line("<fg=cyan>▸ {$organization->name}</> ({$organization->id})");

            $lignes = [];
            $erreursDetail = [];

            if (in_array('depense', $types, true)) {
                $lignes[] = $this->traiterDepenses($organization, $depuis, $jusqua, $dryRun, $depenseService, $ecritures, $erreursDetail);
            }
            if (in_array('fiche', $types, true)) {
                $lignes[] = $this->traiterFiches($organization, $depuis, $jusqua, $dryRun, $ficheService, $ecritures, $erreursDetail);
            }
            if (in_array('paiement-fiche', $types, true)) {
                $lignes[] = $this->traiterPaiementsFiche($organization, $depuis, $jusqua, $dryRun, $ficheService, $ecritures, $erreursDetail);
            }
            if (in_array('vente', $types, true)) {
                $lignes[] = $this->traiterVentes($organization, $depuis, $jusqua, $dryRun, $venteService, $ecritures, $erreursDetail);
            }
            if (in_array('encaissement', $types, true)) {
                $lignes[] = $this->traiterEncaissements($organization, $depuis, $jusqua, $dryRun, $venteService, $ecritures, $erreursDetail);
            }

            $this->table(
                ['Type', 'Éligibles', 'Déjà comptabilisés', $dryRun ? 'À comptabiliser' : 'Comptabilisés', 'Ignorés (montant nul...)', 'Erreurs'],
                $lignes
            );

            foreach ($erreursDetail as $e) {
                $this->warn("  ✗ {$e['type']} {$e['id']} : {$e['erreur']}");
                $totalErreurs++;
            }
        }

        $this->newLine();
        if ($totalErreurs > 0) {
            $this->error("{$totalErreurs} anomalie(s) détectée(s) — voir le détail ci-dessus et storage/logs/laravel.log. Le rattrapage n'est PAS terminé proprement tant qu'elles subsistent.");
        } else {
            $this->info('Aucune anomalie détectée.');
        }
        if ($dryRun) {
            $this->comment('Dry-run : rien n\'a été écrit en base. Relancer sans --dry-run pour appliquer, puis `php artisan comptabilite:auditer` pour contrôler.');
        }

        return $totalErreurs > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ── Traitement par type ──────────────────────────────────────────────────

    private function traiterDepenses(
        Organization $org, ?Carbon $depuis, ?Carbon $jusqua, bool $dryRun,
        DepenseComptabilisationService $service, EcritureComptableService $ecritures, array &$erreursDetail
    ): array {
        // Catégorie EMPLOYE exclue à la source (hors périmètre V1, cf.
        // DepenseComptabilisationService) : ne compte ni comme éligible ni comme
        // ignorée, elle n'appartient simplement pas à ce domaine.
        $query = Depense::where('organization_id', $org->id)
            ->where('statut', StatutDepense::VALIDE->value)
            ->whereHas('depenseType', fn (Builder $q) => $q->where('categorie', '!=', CategorieDepense::EMPLOYE->value))
            ->when($depuis, fn (Builder $q) => $q->where('date_depense', '>=', $depuis))
            ->when($jusqua, fn (Builder $q) => $q->where('date_depense', '<=', $jusqua));

        $compteurs = $this->compteursVides();
        $query->with('depenseType')->chunkById(100, function (Collection $depenses) use ($org, $service, $ecritures, $dryRun, &$compteurs, &$erreursDetail) {
            foreach ($depenses as $depense) {
                $evenement = $service->evenementPour($depense);
                if (! $evenement) {
                    // Filet de sécurité : ne devrait plus arriver vu le filtre ci-dessus.
                    continue;
                }

                $this->traiterUn(
                    $depense, $org->id, $evenement,
                    fn (Model $d) => $service->comptabiliserDepenseValidee($d),
                    'Depense', $dryRun, $ecritures, $compteurs, $erreursDetail
                );
            }
        });

        return $this->ligneRapport('Dépenses', $compteurs, $dryRun);
    }

    private function traiterFiches(
        Organization $org, ?Carbon $depuis, ?Carbon $jusqua, bool $dryRun,
        FicheComptabilisationService $service, EcritureComptableService $ecritures, array &$erreursDetail
    ): array {
        $query = PaiementFiche::where('organization_id', $org->id)
            ->whereIn('beneficiaire_type', ['proprietaire', 'livreur'])
            ->whereHas('periode', fn (Builder $q) => $q->whereIn('statut', [
                StatutPeriodePaiement::VALIDEE->value,
                StatutPeriodePaiement::CLOTUREE->value,
            ]))
            ->when($depuis, fn (Builder $q) => $q->whereHas('periode', fn ($p) => $p->where('date_fin', '>=', $depuis)))
            ->when($jusqua, fn (Builder $q) => $q->whereHas('periode', fn ($p) => $p->where('date_fin', '<=', $jusqua)));

        $compteurs = $this->compteursVides();
        $query->with('periode')->chunkById(100, function (Collection $fiches) use ($service, $ecritures, $dryRun, &$compteurs, &$erreursDetail) {
            foreach ($fiches as $fiche) {
                $evenement = $fiche->beneficiaire_type === 'livreur'
                    ? EvenementComptable::FICHE_LIVREUR_VALIDEE
                    : EvenementComptable::FICHE_PROPRIETAIRE_VALIDEE;

                $this->traiterUn(
                    $fiche, $fiche->organization_id, $evenement,
                    fn (Model $f) => $service->comptabiliserFicheValidee($f),
                    'PaiementFiche', $dryRun, $ecritures, $compteurs, $erreursDetail
                );
            }
        });

        return $this->ligneRapport('Fiches de commission (engagement)', $compteurs, $dryRun);
    }

    private function traiterPaiementsFiche(
        Organization $org, ?Carbon $depuis, ?Carbon $jusqua, bool $dryRun,
        FicheComptabilisationService $service, EcritureComptableService $ecritures, array &$erreursDetail
    ): array {
        $query = PaiementFichePaiement::where('organization_id', $org->id)
            ->whereHas('fiche', fn (Builder $q) => $q->whereIn('beneficiaire_type', ['proprietaire', 'livreur']))
            ->when($depuis, fn (Builder $q) => $q->where('date_paiement', '>=', $depuis))
            ->when($jusqua, fn (Builder $q) => $q->where('date_paiement', '<=', $jusqua));

        $compteurs = $this->compteursVides();
        $query->with('fiche')->chunkById(100, function (Collection $paiements) use ($service, $ecritures, $dryRun, &$compteurs, &$erreursDetail) {
            foreach ($paiements as $paiement) {
                $evenement = $paiement->fiche?->beneficiaire_type === 'livreur'
                    ? EvenementComptable::PAIEMENT_LIVREUR
                    : EvenementComptable::PAIEMENT_PROPRIETAIRE;

                $this->traiterUn(
                    $paiement, $paiement->organization_id, $evenement,
                    fn (Model $p) => $service->comptabiliserPaiementFiche($p),
                    'PaiementFichePaiement', $dryRun, $ecritures, $compteurs, $erreursDetail
                );
            }
        });

        return $this->ligneRapport('Paiements de fiche (règlement)', $compteurs, $dryRun);
    }

    private function traiterVentes(
        Organization $org, ?Carbon $depuis, ?Carbon $jusqua, bool $dryRun,
        VenteComptabilisationService $service, EcritureComptableService $ecritures, array &$erreursDetail
    ): array {
        $query = FactureVente::where('organization_id', $org->id)
            ->whereIn('statut_facture', [
                StatutFactureVente::IMPAYEE->value,
                StatutFactureVente::PARTIEL->value,
                StatutFactureVente::PAYEE->value,
            ])
            // Pas de colonne dédiée "date de facturation définitive" — filtre sur
            // created_at, cohérent avec le repli utilisé par VenteComptabilisationService
            // pour dateComptable côté vente directe (creerFactureDirecte()).
            ->when($depuis, fn (Builder $q) => $q->where('created_at', '>=', $depuis))
            ->when($jusqua, fn (Builder $q) => $q->where('created_at', '<=', $jusqua->copy()->endOfDay()));

        $compteurs = $this->compteursVides();
        $query->with('commande')->chunkById(100, function (Collection $factures) use ($service, $ecritures, $dryRun, &$compteurs, &$erreursDetail) {
            foreach ($factures as $facture) {
                $this->traiterUn(
                    $facture, $facture->organization_id, EvenementComptable::VENTE_FACTUREE,
                    fn (Model $f) => $service->comptabiliserVenteFacturee($f),
                    'FactureVente', $dryRun, $ecritures, $compteurs, $erreursDetail
                );
            }
        });

        return $this->ligneRapport('Ventes facturées', $compteurs, $dryRun);
    }

    private function traiterEncaissements(
        Organization $org, ?Carbon $depuis, ?Carbon $jusqua, bool $dryRun,
        VenteComptabilisationService $service, EcritureComptableService $ecritures, array &$erreursDetail
    ): array {
        // encaissements_ventes ne porte pas organization_id — filtre via la facture.
        $query = EncaissementVente::whereHas('facture', fn (Builder $q) => $q->where('organization_id', $org->id))
            ->when($depuis, fn (Builder $q) => $q->where('date_encaissement', '>=', $depuis))
            ->when($jusqua, fn (Builder $q) => $q->where('date_encaissement', '<=', $jusqua));

        $compteurs = $this->compteursVides();
        $query->with('facture')->chunkById(100, function (Collection $encaissements) use ($org, $service, $ecritures, $dryRun, &$compteurs, &$erreursDetail) {
            foreach ($encaissements as $encaissement) {
                $this->traiterUn(
                    $encaissement, $org->id, EvenementComptable::ENCAISSEMENT_VENTE_RECU,
                    fn (Model $e) => $service->comptabiliserEncaissementVente($e),
                    'EncaissementVente', $dryRun, $ecritures, $compteurs, $erreursDetail
                );
            }
        });

        return $this->ligneRapport('Encaissements clients', $compteurs, $dryRun);
    }

    // ── Cœur commun : idempotence + dry-run (transaction annulée) ────────────

    /**
     * @param  callable(Model): (PieceComptable|null)  $comptabiliserFn
     */
    private function traiterUn(
        Model $source, string $organizationId, EvenementComptable $evenement,
        callable $comptabiliserFn, string $typeLabel, bool $dryRun,
        EcritureComptableService $ecritures, array &$compteurs, array &$erreursDetail
    ): void {
        $compteurs['eligibles']++;

        // Même clé que compta_pieces_idempotency_unique — aucun système parallèle.
        if ($ecritures->pieceExistantePour($organizationId, $source, $evenement)) {
            $compteurs['deja_comptabilises']++;

            return;
        }

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $piece = $comptabiliserFn($source);
                DB::rollBack();
                $piece ? $compteurs['comptabilises']++ : $compteurs['ignores']++;
            } catch (Throwable $e) {
                DB::rollBack();
                $compteurs['erreurs']++;
                $erreursDetail[] = ['type' => $typeLabel, 'id' => $source->getKey(), 'erreur' => $e->getMessage()];
            }

            return;
        }

        try {
            $piece = $comptabiliserFn($source);
            $piece ? $compteurs['comptabilises']++ : $compteurs['ignores']++;
        } catch (Throwable $e) {
            $compteurs['erreurs']++;
            $erreursDetail[] = ['type' => $typeLabel, 'id' => $source->getKey(), 'erreur' => $e->getMessage()];
        }
    }

    private function compteursVides(): array
    {
        return ['eligibles' => 0, 'deja_comptabilises' => 0, 'comptabilises' => 0, 'ignores' => 0, 'erreurs' => 0];
    }

    private function ligneRapport(string $label, array $c, bool $dryRun): array
    {
        return [$label, $c['eligibles'], $c['deja_comptabilises'], $c['comptabilises'], $c['ignores'], $c['erreurs']];
    }

    // ── Résolution des options ───────────────────────────────────────────────

    /** @return list<string>|null */
    private function resolveTypes(): ?array
    {
        $types = $this->option('type');
        if (empty($types)) {
            return self::TYPES_VALIDES;
        }

        $invalides = array_diff($types, self::TYPES_VALIDES);
        if (! empty($invalides)) {
            $this->error('Type(s) invalide(s) : '.implode(', ', $invalides).'. Valeurs acceptées : '.implode(', ', self::TYPES_VALIDES).'.');

            return null;
        }

        return $types;
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

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function resolveDates(): array
    {
        $depuis = $this->option('depuis') ? Carbon::parse($this->option('depuis'))->startOfDay() : null;
        $jusqua = $this->option('jusqua') ? Carbon::parse($this->option('jusqua'))->endOfDay() : null;

        return [$depuis, $jusqua];
    }
}
