<?php

namespace App\Support\Livreurs;

use App\Enums\StatutCommission;
use App\Enums\StatutFactureVente;
use App\Features\ModuleFeature;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppePart;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\VehiculeCapaciteService;
use App\Support\Commission\CommissionKpiBuckets;
use App\Support\Commission\CommissionProcessusFilter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Données de la fiche livreur côté backoffice (onglets Informations / Véhicule & équipe /
 * Commissions / Factures). Chaque onglet sensible n'est rempli que si l'utilisateur a réellement
 * accès à l'écran détaillé correspondant (permission + module actif) : null = onglet masqué.
 *
 * Aucun calcul métier propre ici — les montants de commission viennent de CommissionKpiBuckets
 * (même partition que l'écran Commissions > Livreurs), le périmètre des factures reprend le
 * filtre `livreur_id` de IndexFactureVenteController (lien « Voir toutes les factures »).
 */
final class FicheLivreurStaffData
{
    private const NB_RECENTES = 10;

    public function __construct(private readonly VehiculeCapaciteService $capacites) {}

    public function pour(Livreur $livreur, User $user): array
    {
        // load() et non loadMissing() : le contrôleur a déjà chargé les véhicules avec 2 colonnes seulement.
        $livreur->load([
            'equipes.vehicule.typeVehicule',
            'equipes.vehicule.site',
            'equipes.membres.livreur',
        ]);
        $org = $user->organization;

        return [
            'equipes' => $this->equipes($livreur, $user),
            'commissions' => $user->canReadCommissions() && ModuleService::isActive(ModuleFeature::COMPTABILITE, $org)
                ? $this->commissions($livreur)
                : null,
            'factures' => $user->can('viewAny', CommandeVente::class) && ModuleService::isActive(ModuleFeature::VENTES, $org)
                ? $this->factures($livreur, $user)
                : null,
        ];
    }

    private function equipes(Livreur $livreur, User $user): array
    {
        $peutVoirVehicule = $user->can('vehicules.read')
            && ModuleService::isActive(ModuleFeature::VEHICULES, $user->organization);

        return $livreur->equipes
            ->sortByDesc('is_active')
            ->map(function ($equipe) use ($livreur, $peutVoirVehicule) {
                $vehicule = $equipe->vehicule;

                return [
                    'id' => $equipe->id,
                    'is_active' => (bool) $equipe->is_active,
                    'role' => $equipe->pivot->role,
                    'rejoint_le' => $equipe->pivot->created_at?->format('d/m/Y'),
                    'vehicule' => $vehicule ? [
                        'id' => $vehicule->id,
                        'nom' => $vehicule->nom_vehicule,
                        'immatriculation' => $vehicule->immatriculation,
                        'type_label' => $vehicule->typeVehicule?->nom,
                        'site_nom' => $vehicule->site?->nom,
                        'is_active' => (bool) $vehicule->is_active,
                        'capacites' => $this->capacites->capacitesParCategorieAvecNoms($vehicule),
                        'url' => $peutVoirVehicule ? route('vehicules.show', $vehicule->id) : null,
                    ] : null,
                    'coequipiers' => $equipe->membres
                        ->reject(fn ($m) => $m->livreur_id === $livreur->id)
                        ->map(fn ($m) => [
                            'id' => $m->livreur_id,
                            'nom' => $m->livreur?->libelleAffichage() ?? '—',
                            'role' => $m->role,
                            'url' => $m->livreur ? route('livreurs.show', $m->livreur_id) : null,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function commissions(Livreur $livreur): array
    {
        $parts = CommissionEnveloppePart::with(['enveloppe.source', 'enveloppe.processus'])
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_LIVREUR)
            ->where('beneficiaire_id', $livreur->id)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $livreur->organization_id))
            ->orderByDesc('enveloppe_id')
            ->get();

        $recentes = $parts
            ->groupBy('enveloppe_id')
            ->take(self::NB_RECENTES)
            ->map(function (Collection $groupe) {
                $premiere = $groupe->first();
                $enveloppe = $premiere->enveloppe;
                $annulee = $premiere->statut === StatutCommission::ANNULEE;
                $montant = (float) $groupe->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer);

                return [
                    'id' => $enveloppe?->id,
                    'reference' => $enveloppe?->source?->reference,
                    'date' => $enveloppe?->earned_at ? Carbon::parse($enveloppe->earned_at)->format('d/m/Y') : null,
                    'processus_label' => CommissionProcessusFilter::labelFor($enveloppe?->processus?->code),
                    'montant' => $montant,
                    'reste' => $annulee ? 0.0 : max(0.0, $montant - (float) $groupe->sum('montant_verse')),
                    'statut' => $premiere->statut?->value,
                    'statut_label' => $premiere->statut?->label(),
                ];
            })
            ->values()
            ->all();

        return [
            'kpis' => CommissionKpiBuckets::calculer($parts),
            'recentes' => $recentes,
            'detail_url' => route('comptabilite.commissions.vente.livreur', $livreur->id),
        ];
    }

    private function factures(Livreur $livreur, User $user): array
    {
        $query = FactureVente::query()
            ->where('organization_id', $livreur->organization_id)
            ->whereHas('commande.vehicule.equipe.membres', fn ($q) => $q->where('livreur_id', $livreur->id));

        // Même restriction d'agence que la liste des factures pour un non-admin.
        if (! $user->voitToutesLesAgences()) {
            $siteIds = $user->sites()->pluck('sites.id');
            if ($siteIds->isNotEmpty()) {
                $query->whereHas('commande', fn ($q) => $q->whereIn('site_id', $siteIds));
            }
        }

        $factures = $query
            ->with(['commande.client', 'commande.vehicule:id,nom_vehicule'])
            ->withSum('encaissements', 'montant')
            ->orderByDesc('created_at')
            ->get();

        $restant = fn (FactureVente $f) => max(0.0, (float) $f->montant_net - (float) $f->encaissements_sum_montant);
        $actives = $factures->reject(fn (FactureVente $f) => $f->statut_facture === StatutFactureVente::ANNULEE);

        return [
            'totaux' => [
                'nb' => $actives->count(),
                'montant' => (float) $actives->sum('montant_net'),
                'a_encaisser' => (float) $actives
                    ->reject(fn (FactureVente $f) => $f->statut_facture === StatutFactureVente::PAYEE)
                    ->sum($restant),
            ],
            'recentes' => $factures
                ->take(self::NB_RECENTES)
                ->map(fn (FactureVente $f) => [
                    'id' => $f->id,
                    'reference' => $f->reference,
                    'client_nom' => $f->commande?->client?->nom_complet,
                    'vehicule_nom' => $f->commande?->vehicule?->nom_vehicule,
                    'date' => $f->created_at?->format('d/m/Y'),
                    'montant_net' => (float) $f->montant_net,
                    'montant_restant' => $f->statut_facture === StatutFactureVente::ANNULEE ? 0.0 : $restant($f),
                    'statut' => $f->statut_facture?->value,
                    'statut_label' => $f->statut_facture?->label(),
                    'url' => $f->commande_vente_id ? route('ventes.show', $f->commande_vente_id) : null,
                ])
                ->values()
                ->all(),
            'liste_url' => route('factures.index', ['livreur_id' => $livreur->id, 'periode' => 'tout']),
        ];
    }
}
