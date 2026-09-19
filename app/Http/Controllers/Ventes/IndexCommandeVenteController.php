<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class IndexCommandeVenteController extends Controller
{
    // Message court, affiché près du bouton « Nouvelle commande » / au survol quand il est
    // désactivé (cf. Ventes/Index.vue, prop raison_blocage_commande).
    private const MESSAGE_BLOCAGE_TOOLTIP = 'Aucun stock disponible pour ce site.';

    /**
     * Sert à la fois `ventes.index` et `distributions.index` (cf. routes/web.php) — même
     * contrôleur, même données, filtrées par nom de route (fait serveur, jamais un paramètre
     * modifiable côté client).
     */
    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', CommandeVente::class);

        $user = auth()->user();
        $orgId = $user->organization_id;

        $periode = $request->input('periode', 'all');
        $statuts = array_values(array_filter((array) $request->input('statuts', [])));
        $statutFacture = $request->input('statut_facture');
        $statutCommission = $request->input('statut_commission');
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));
        $dateDebut = $request->input('date_debut');
        $dateFin = $request->input('date_fin');
        $vehicule = $request->input('vehicule');
        $proprietaire = $request->input('proprietaire');
        $livreur = $request->input('livreur');
        $numeroCommande = $request->input('numero_commande');
        $client = $request->input('client');

        $query = CommandeVente::with([
            'vehicule.proprietaire',
            'vehicule.equipe.livreurs',
            'client',
            'site',
            'facture.encaissements.creator',
            'lignes:id,commande_vente_id,quantite_demandee,quantite_chargee,quantite_livree',
        ])
            ->where('organization_id', $orgId)
            ->orderByDesc('created_at');

        if ($user->isAdmin()) {
            if (! empty($siteIds)) {
                $query->whereIn('site_id', $siteIds);
            }
        } else {
            $userSiteIds = $user->sites()->pluck('sites.id');
            if ($userSiteIds->isNotEmpty()) {
                $query->whereIn('site_id', $userSiteIds);
            }
        }

        if ($dateDebut || $dateFin) {
            if ($dateDebut) {
                $query->whereDate('created_at', '>=', $dateDebut);
            }
            if ($dateFin) {
                $query->whereDate('created_at', '<=', $dateFin);
            }
        } else {
            match ($periode) {
                'today' => $query->whereDate('created_at', Carbon::today()),
                'week' => $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]),
                'month' => $query->whereYear('created_at', Carbon::now()->year)->whereMonth('created_at', Carbon::now()->month),
                default => null,
            };
        }

        if (! empty($statuts)) {
            $query->whereIn('statut', $statuts);
        }

        if ($statutFacture) {
            $query->whereHas('facture', fn ($q) => $q->where('statut_facture', $statutFacture));
        }

        if ($statutCommission) {
            $query->whereHas('commissions', fn ($q) => $q->where('statut', $statutCommission));
        }

        if ($numeroCommande) {
            $query->where('reference', 'like', "%{$numeroCommande}%");
        }

        if ($vehicule) {
            $query->whereHas('vehicule', function ($q) use ($vehicule) {
                $q->where('nom_vehicule', 'like', "%{$vehicule}%")
                    ->orWhere('immatriculation', 'like', "%{$vehicule}%");
            });
        }

        if ($vehiculeNom = $request->input('vehicule_nom')) {
            $query->whereHas('vehicule', fn ($q) => $q->where('nom_vehicule', 'like', "%{$vehiculeNom}%"));
        }

        if ($vehiculeImmat = $request->input('vehicule_immatriculation')) {
            $query->whereHas('vehicule', fn ($q) => $q->where('immatriculation', 'like', "%{$vehiculeImmat}%"));
        }

        if ($proprietaire) {
            $query->whereHas('vehicule.proprietaire', function ($q) use ($proprietaire) {
                $q->whereHas('personne', function ($p) use ($proprietaire) {
                    $p->where('nom', 'like', "%{$proprietaire}%")
                        ->orWhere('prenom', 'like', "%{$proprietaire}%")
                        ->orWhere('telephone', 'like', "%{$proprietaire}%");
                });
            });
        }

        if ($proprietaireNom = $request->input('proprietaire_nom')) {
            $query->whereHas('vehicule.proprietaire', fn ($q) => $q->whereHas('personne', fn ($p) => $p->where('nom', 'like', "%{$proprietaireNom}%")
                ->orWhere('prenom', 'like', "%{$proprietaireNom}%")));
        }

        if ($proprietaireTel = $request->input('proprietaire_telephone')) {
            $query->whereHas('vehicule.proprietaire', fn ($q) => $q->whereHas('personne', fn ($p) => $p->where('telephone', 'like', "%{$proprietaireTel}%")));
        }

        if ($livreur) {
            // nom/prenom conservés en recherche pour compatibilité (autres
            // usages éventuels), mais nom_complet est le champ réellement
            // saisi/affiché côté Eau La Maman.
            $query->whereHas('vehicule.equipe.livreurs', function ($q) use ($livreur) {
                $q->where('livreurs.nom_complet', 'like', "%{$livreur}%")
                    ->orWhereHas('personne', function ($p) use ($livreur) {
                        $p->where('nom', 'like', "%{$livreur}%")
                            ->orWhere('prenom', 'like', "%{$livreur}%")
                            ->orWhere('telephone', 'like', "%{$livreur}%");
                    });
            });
        }

        if ($livreurNom = $request->input('livreur_nom')) {
            $query->whereHas('vehicule.equipe.livreurs', fn ($q) => $q->whereHas('personne', fn ($p) => $p->where('nom', 'like', "%{$livreurNom}%")));
        }

        if ($livreurPrenom = $request->input('livreur_prenom')) {
            $query->whereHas('vehicule.equipe.livreurs', fn ($q) => $q->whereHas('personne', fn ($p) => $p->where('prenom', 'like', "%{$livreurPrenom}%")));
        }

        if ($livreurTel = $request->input('livreur_telephone')) {
            $query->whereHas('vehicule.equipe.livreurs', fn ($q) => $q->whereHas('personne', fn ($p) => $p->where('telephone', 'like', "%{$livreurTel}%")));
        }

        if ($livreurRole = $request->input('livreur_role')) {
            $query->whereHas('vehicule.equipe.membres', fn ($q) => $q->where('role', $livreurRole));
        }

        if ($client) {
            $query->whereHas('client', function ($q) use ($client) {
                $q->where('nom', 'like', "%{$client}%")
                    ->orWhere('prenom', 'like', "%{$client}%")
                    ->orWhere('telephone', 'like', "%{$client}%");
            });
        }

        if ($clientNom = $request->input('client_nom')) {
            $query->whereHas('client', fn ($q) => $q->where('nom', 'like', "%{$clientNom}%")
                ->orWhere('prenom', 'like', "%{$clientNom}%"));
        }

        if ($clientTel = $request->input('client_telephone')) {
            $query->whereHas('client', fn ($q) => $q->where('telephone', 'like', "%{$clientTel}%"));
        }

        // Distribution : même liste, même contrôleur, filtrée par nom de route (fait serveur,
        // jamais un paramètre modifiable côté client) — cf. routes/web.php.
        $natureFiltree = $request->route()?->getName() === 'distributions.index'
            ? NatureOperation::DISTRIBUTION_CLIENT
            : NatureOperation::VENTE_STANDARD;
        $query->where('nature_operation', $natureFiltree->value);

        $commandes = $query->get();
        $nonAnnulees = $commandes->filter(fn ($c) => ! $c->isAnnulee());
        $cloturees = $commandes->filter(fn ($c) => $c->isCloturee());

        $totaux = [
            'total_montant' => (float) $nonAnnulees->sum('total_commande'),
            'nb_total' => $nonAnnulees->count(),
            'total_a_encaisser' => (float) $commandes
                ->filter(fn ($c) => $c->facture && ! $c->facture->isAnnulee())
                ->sum(fn ($c) => (float) $c->facture->montant_restant),
            'deja_paye' => (float) $commandes
                ->filter(fn ($c) => $c->facture && ! $c->facture->isAnnulee())
                ->sum(fn ($c) => (float) $c->facture->montant_encaisse),
            'nb_cloturees' => $cloturees->count(),
            'montant_cloturees' => (float) $cloturees->sum('total_commande'),
        ];

        $mapped = $commandes->map(fn (CommandeVente $c) => $this->mapCommandeForIndex($c, $user));

        $sites = $user->isAdmin()
            ? Site::where('organization_id', $orgId)->orderBy('nom')->get()
                ->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom])->values()
            : [];

        // Options du filtre Véhicule de la modale d'export (cf. Ventes/Index.vue) — même périmètre
        // de sites qu'ailleurs sur cette page : tous les véhicules de l'organisation pour un admin,
        // uniquement ceux des sites de l'utilisateur sinon.
        $vehiculesQuery = Vehicule::where('organization_id', $orgId);
        if (! $user->isAdmin()) {
            $userSiteIds = $user->sites()->pluck('sites.id');
            $vehiculesQuery->whereIn('site_id', $userSiteIds);
        }
        $vehicules = $vehiculesQuery->orderBy('nom_vehicule')->get(['id', 'nom_vehicule', 'immatriculation'])
            ->map(fn (Vehicule $v) => [
                'id' => $v->id,
                'nom' => $v->immatriculation ? "{$v->nom_vehicule} ({$v->immatriculation})" : $v->nom_vehicule,
            ])->values();

        // Bouton « Nouvelle commande » : bloqué uniquement quand la politique globale interdit
        // la vente sans stock ET que le site personnel de l'utilisateur (celui qui sera
        // effectivement utilisé par create()/store(), cf. getUserSiteModel()) n'a absolument
        // rien à vendre. Un utilisateur sans aucun site attaché (cas déjà géré par
        // getUserSiteModel(), qui abort() dès l'accès à create()) n'est jamais bloqué ICI —
        // cette page reste consultable, le vrai gate se déclenche à create()/store().
        $canCreerCommande = true;
        $raisonBlocageCommande = null;
        if ($userSiteId = $this->getUserSiteIdOrNull()) {
            $canCreerCommande = CommandeVenteService::siteAutoriseNouvelleCommande($orgId, $userSiteId);
            if (! $canCreerCommande) {
                $raisonBlocageCommande = self::MESSAGE_BLOCAGE_TOOLTIP;
            }
        }

        return Inertia::render('Ventes/Index', [
            'commandes' => $mapped->values(),
            'totaux' => $totaux,
            'nature_filtree' => $natureFiltree->value,
            'page_title' => $natureFiltree === NatureOperation::DISTRIBUTION_CLIENT ? 'Distribution' : 'Ventes',
            'periode' => $periode,
            'statuts_actifs' => $statuts,
            'statuts' => StatutCommandeVente::options(),
            'sites' => $sites,
            'vehicules' => $vehicules,
            'is_admin' => $user->isAdmin(),
            'can_creer_commande' => $canCreerCommande,
            'raison_blocage_commande' => $raisonBlocageCommande,
            'filters' => [
                'site_ids' => $siteIds,
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'statut_facture' => $statutFacture,
                'statut_commission' => $statutCommission,
                'vehicule' => $vehicule,
                'proprietaire' => $proprietaire,
                'livreur' => $livreur,
                'numero_commande' => $numeroCommande,
                'client' => $client,
            ],
        ]);
    }

    private function mapCommandeForIndex(CommandeVente $c, mixed $user): array
    {
        // Identité de processus de commission (Vente / Distribution client / Transfert grossiste),
        // calculée via la même source unique que la génération réelle (cf.
        // CommissionEnveloppeGenerator::genererPourCommandeVente()) — jamais lue depuis les
        // CommissionEnveloppe déjà générées, pour rester affichée même avant tout déclenchement
        // (commande en brouillon ou à charger).
        $processusCode = CommissionProcessusDefaults::identiteCodePourVente(
            $c->nature_operation ?? NatureOperation::VENTE_STANDARD,
            $c->client?->type,
            $c->mode_remise_grossiste,
        );

        return [
            'id' => $c->id,
            'reference' => $c->reference,
            'statut' => $c->statut?->value,
            'statut_label' => $c->statut_label,
            'statut_color' => $c->statut?->color(),
            'nature_operation' => $c->nature_operation?->value,
            'processus_code' => $processusCode,
            'processus_label' => CommissionProcessusDefaults::libelle($processusCode),
            'total_commande' => (float) $c->total_commande,
            'quantite_totale' => $c->quantite_totale,
            'vehicule_nom' => $c->vehicule?->nom_vehicule,
            'vehicule_immatriculation' => $c->vehicule?->immatriculation,
            'vehicule_photo_url' => $c->vehicule?->photo_url,
            'chauffeur_nom' => $c->vehicule?->equipe?->livreurs
                ?->first(fn ($l) => ($l->pivot->role ?? null) === 'chauffeur')
                ?->nom_complet,
            'client_nom' => $c->client?->nom_complet,
            'client_telephone' => $c->client?->telephone,
            'site_nom' => $c->site?->nom,
            'facture_id' => $c->facture?->id,
            'facture_statut' => $c->facture?->statut_facture?->value,
            'facture_statut_label' => $c->facture?->statut_facture?->label(),
            'facture_montant_encaisse' => $c->facture ? (float) $c->facture->montant_encaisse : null,
            'facture_montant_restant' => $c->facture ? (float) $c->facture->montant_restant : null,
            'encaissements' => $c->facture ? $c->facture->encaissements->map(fn ($e) => [
                'id' => $e->id,
                'montant' => (float) $e->montant,
                'date_encaissement' => $e->date_encaissement?->format('d/m/Y'),
                'heure' => $e->created_at?->format('H:i'),
                'mode_paiement_label' => $e->mode_paiement?->label(),
                'operateur_mobile_money_label' => $e->operateur_mobile_money?->label(),
                'reference_paiement' => $e->reference_paiement,
                'created_by' => $e->creator?->name,
            ])->values() : [],
            'created_at' => $c->created_at?->format('d/m/Y'),
            'is_annulee' => $c->isAnnulee(),
            'is_brouillon' => $c->isBrouillon(),
            'is_facturation' => $c->isFacturation(),
            'can_modifier' => $user->can('modifierContenu', $c),
            'can_confirmer' => $c->isBrouillon() && $user->can('confirmer', $c),
            'can_annuler' => $c->statut->isAnnulable()
                && (! $c->facture || (float) $c->facture->montant_encaisse === 0.0)
                && $user->can('annuler', $c),
        ];
    }

    /**
     * Variante non-bloquante de getUserSiteModel() (cf. CommandeVenteFormBuilder) : cette page
     * reste consultable même par un utilisateur sans aucun site attaché (contrairement à
     * create()/store(), qui abortent) — null désactive simplement le calcul du blocage du
     * bouton « Nouvelle commande » plutôt que de faire 403 toute la liste des commandes.
     */
    private function getUserSiteIdOrNull(): ?string
    {
        $user = auth()->user();

        return $user->sites()->wherePivot('is_default', true)->value('sites.id')
            ?? $user->sites()->value('sites.id');
    }
}
