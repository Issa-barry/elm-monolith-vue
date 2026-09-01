<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\CategorieTarifaireVehicule;
use App\Enums\ClientType;
use App\Enums\CommissionGenerationDeclenchePar;
use App\Enums\CommissionGenerationStatut;
use App\Enums\ModeTarification;
use App\Enums\MotifAnnulation;
use App\Enums\NatureOperation;
use App\Enums\PrixOrigine;
use App\Enums\ProduitStatut;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Jobs\NotifierLivreursCommandeVenteJob;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionGenerationAttempt;
use App\Models\CommissionProcessus;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use App\Services\AuditLogService;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\Commission\CommissionPartageLivraisonCategorieChecker;
use App\Services\PrixUsineResolver;
use App\Services\PrixVenteNatureResolver;
use App\Services\SolvabiliteService;
use App\Services\VehiculeCapaciteService;
use App\Services\VehiculeCommandeContextResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CommandeVenteController extends Controller
{
    private const DATE_DISPLAY_FORMAT = 'd/m/Y';

    private const LIGNES_REQUIRED_MESSAGE = 'Au moins une ligne de commande est requise.';

    private const UNIT_PRICE_UPDATE_PERMISSION = 'ventes.prix.update';

    // Message court, affiché près du bouton « Nouvelle commande » / au survol quand il est
    // désactivé (cf. Ventes/Index.vue, prop raison_blocage_commande).
    private const MESSAGE_BLOCAGE_TOOLTIP = 'Aucun stock disponible pour ce site.';

    // Message affiché en toast (top-right) quand un accès direct à la création est bloqué —
    // jamais une page 403 : redirection vers la liste des ventes + flash 'error' (cf. create()/
    // store() et Ventes/Index.vue, qui l'affiche via useToast() au montage).
    private const MESSAGE_BLOCAGE_TOAST = 'Impossible de créer une commande : aucun stock disponible pour ce site.';

    public function __construct(
        private readonly AuditLogService $auditService,
        private readonly VehiculeCapaciteService $vehiculeCapaciteService,
        private readonly SolvabiliteService $solvabiliteService,
    ) {}

    // ── Check solvabilité ─────────────────────────────────────────────────────

    /**
     * Simple miroir de lecture de SolvabiliteService::evaluer() — jamais de logique de calcul
     * ici, jamais de blocage : ce n'est qu'un aperçu pour guider l'utilisateur AVANT
     * soumission. Le vrai gate est enforceImpayesBlocking() ci-dessous, sur le même service.
     */
    public function checkSolvabilite(Request $request): JsonResponse
    {
        $request->validate([
            'vehicule_id' => 'nullable|exists:vehicules,id',
            'client_id' => 'nullable|exists:clients,id',
        ]);

        $orgId = auth()->user()->organization_id;

        return response()->json($this->solvabiliteService->evaluer(
            $orgId,
            $request->input('vehicule_id'),
            $request->input('client_id'),
        ));
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
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

    // ── Create ────────────────────────────────────────────────────────────────

    public function create(): Response|RedirectResponse
    {
        $this->authorize('create', CommandeVente::class);

        $orgId = auth()->user()->organization_id;
        $userSite = $this->getUserSiteModel();
        if ($redirect = $this->redirectSiCreationBloquee($orgId, $userSite->id)) {
            return $redirect;
        }

        return Inertia::render('Ventes/Create', [
            'produits' => $this->produitsActifs($orgId, $userSite->id),
            'vehicules' => $this->vehiculesActifs($orgId),
            // Pool séparé, jamais fusionné au précédent : un véhicule logistique-only
            // (livraison_vente=false) ne doit jamais être proposé pour une vente standard, ni
            // l'inverse (cf. règle métier distribution client du 31/08/2026). Le frontend choisit
            // la liste à interroger selon le type de client sélectionné.
            'vehicules_distribution' => $this->vehiculesLogistiques($orgId),
            'clients' => $this->clientsActifs($orgId),
            'user_site' => $this->getUserSite(),
            'can_modifier_qte' => auth()->user()->can('ventes.qte.update'),
            'autoriser_saisie_dessous_qte_max' => Parametre::isVentesAutorisationSaisieDessousQteMax($orgId),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CommandeVente::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $userSite = $this->getUserSiteModel();
        // Défense en profondeur : le bouton « Nouvelle commande » est déjà désactivé côté
        // Ventes/Index et create() refuse déjà l'accès direct à la page — ce contrôle empêche
        // en plus un POST direct (contournement de l'UI) de créer une commande sur un site sans
        // aucun stock vendable, quand la politique globale l'interdit. Même traitement que
        // create() : jamais un 403, une redirection + toast (cf. redirectSiCreationBloquee()).
        if ($redirect = $this->redirectSiCreationBloquee($orgId, $userSite->id)) {
            return $redirect;
        }

        $data = $request->validate($this->commandeValidationRules(), $this->commandeValidationMessages());

        $this->ensureVehiculeOrClientSelected($data);

        $client = $this->resolveClientForTarification($data['client_id'] ?? null);
        // Chargé une seule fois (organisation vérifiée, équipe/chauffeur actif eager-chargés) —
        // sert à dériver nature_operation, à la valider (ensureNatureOperationCoherente()) et au
        // pré-contrôle de partage commission (ensurePartageLivraisonCategorieConfigure()), jamais
        // trois requêtes/dérivations séparées comme avant le 31/08/2026.
        $vehiculePourValidation = $this->resolveVehiculeAvecEquipe($data['vehicule_id'] ?? null, $orgId);
        $natureOperation = $this->resoudreNatureOperation($data, $client, $vehiculePourValidation);

        $this->ensureNatureOperationCoherente($natureOperation, $data['vehicule_id'] ?? null, $vehiculePourValidation);
        $this->ensureQuantiteMatchesVehiculeCapacity($data);
        $this->enforcePrixVentePolicy($data, null, $client);
        $this->ensurePartageLivraisonCategorieConfigure($natureOperation, $vehiculePourValidation, $data['lignes'] ?? []);

        $commande = DB::transaction(function () use ($data, $orgId, $userSite, $client, $natureOperation) {
            // Verrou de ligne sur le véhicule le temps de la transaction : sans cela, deux
            // requêtes concurrentes pour le même véhicule (double clic, deux utilisateurs)
            // passeraient toutes les deux enforceImpayesBlocking() avant qu'aucune des deux
            // commandes ne soit créée, puis créeraient chacune une commande — exactement le
            // doublon que ce contrôle doit empêcher (cf. section « concurrence » de la règle
            // métier). Le verrou est acquis AVANT le contrôle pour que la seconde requête,
            // bloquée par MySQL/Postgres jusqu'au commit de la première, revoie bien la facture
            // fraîchement créée par celle-ci une fois débloquée.
            if (! empty($data['vehicule_id'])) {
                Vehicule::whereKey($data['vehicule_id'])->lockForUpdate()->first();
            }

            $this->enforceImpayesBlocking($data, $orgId);

            $context = VehiculeCommandeContextResolver::resolve($data['vehicule_id'] ?? null, $data['client_id'] ?? null, $natureOperation);
            [$lignesData, $totalCommande] = $this->buildLignesDataAndTotal($data['lignes'], $context->modeTarification, $context->categorieTarifaireVehicule, $client);

            $this->assertStockDisponiblePourLignes($orgId, $userSite->id, $lignesData);

            $commande = CommandeVente::create([
                'organization_id' => $orgId,
                'site_id' => $userSite->id,
                'vehicule_id' => $data['vehicule_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'client_vehicule_id' => $data['client_vehicule_id'] ?? null,
                'total_commande' => $totalCommande,
                'mode_tarification_snapshot' => $context->modeTarification->value,
                'commission_eligible_snapshot' => $context->commissionEligible,
                'nature_operation' => $natureOperation->value,
                'created_by' => auth()->id(),
            ]);

            foreach ($lignesData as $ligneDatum) {
                $commande->lignes()->create($ligneDatum);
            }

            $commande->load(['lignes.variante.produit', 'vehicule', 'client']);
            $this->auditService->record($commande, AuditEvent::CREATED, auth()->user(), null, $this->commandeSnapshot($commande));

            if ($commande->vehicule_id && $commande->lignes->isNotEmpty()) {
                CommandeVenteService::confirmer($commande);
                CommandeVenteActiviteService::log($commande, 'creation_confirmee');
            } else {
                // Vente directe client — passe en FACTURATION + crée la facture
                CommandeVenteService::creerFactureDirecte($commande);
                CommandeVenteActiviteService::log($commande, 'creation_directe');
            }

            return $commande;
        });

        return $commande->isFacturation()
            ? redirect()->route('ventes.show', $commande)->with('success', 'Commande créée. Facture générée — en attente d\'encaissement.')
            : redirect()->route('ventes.show', $commande)->with('success', 'Commande créée et confirmée. En attente de chargement.');
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(CommandeVente $vente): Response
    {
        $this->authorize('view', $vente);

        $commande = $vente;
        $commande->load(['vehicule.proprietaire', 'vehicule.typeVehicule', 'vehicule.equipe.livreurs', 'client', 'site', 'lignes.variante.produit', 'createdBy', 'facture.encaissements.creator', 'commissions', 'activites.user']);

        $commande->cloturerSiComplete();
        $commande->refresh();

        $user = auth()->user();
        $facture = $commande->facture;

        $vehicule = $commande->vehicule;
        $equipe = $vehicule?->equipe;
        $chauffeur = $equipe?->livreurs->first(fn ($l) => ($l->pivot->role ?? null) === 'chauffeur');
        $convoyeurs = $equipe ? $equipe->livreurs->filter(fn ($l) => ($l->pivot->role ?? null) !== 'chauffeur') : collect();

        $lignes = $commande->lignes->map(fn ($l) => [
            'id' => $l->id,
            'variante_id' => $l->variante_id,
            'produit_nom' => $l->libelle_snapshot ?? $l->variante?->produit?->nom,
            'quantite_demandee' => $l->quantite_demandee,
            'quantite_chargee' => $l->quantite_chargee,
            'quantite_livree' => $l->quantite_livree,
            'type_ecart' => $l->type_ecart?->value,
            'type_ecart_label' => $l->type_ecart?->label(),
            'commentaire_ecart' => $l->commentaire_ecart,
            'type_ecart_reception' => $l->type_ecart_reception?->value,
            'type_ecart_reception_label' => $l->type_ecart_reception?->label(),
            'commentaire_ecart_reception' => $l->commentaire_ecart_reception,
            'ecart_chargement' => $l->ecart_chargement,
            'ecart_livraison' => $l->ecart_livraison,
            'prix_usine_snapshot' => (float) $l->prix_usine_snapshot,
            'prix_vente_snapshot' => (float) $l->prix_vente_snapshot,
            'prix_origine_snapshot' => $l->prix_origine_snapshot?->value,
            'total_ligne' => (float) $l->total_ligne,
        ]);

        $historiques = AuditLog::where('organization_id', $commande->organization_id)
            ->where('auditable_type', CommandeVente::class)
            ->where('auditable_id', $commande->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event_code' => $log->event_code,
                'event_label' => $log->event_label,
                'actor_name' => $log->actor_name_snapshot ?? 'Système',
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at->format('d/m/Y H:i'),
            ]);

        $activites = $commande->activites->map(fn ($a) => [
            'id' => $a->id,
            'action' => $a->action,
            'action_label' => $a->action_label,
            'user_name' => $a->user?->name ?? 'Système',
            'created_at' => $a->created_at->format('d/m/Y H:i'),
            'details' => $a->details,
        ]);

        // Backend commun, expérience UI séparée : même construction de données pour les deux
        // natures, seul le composant Vue rendu diffère (présentation uniquement, cf. distributions.show).
        $component = $commande->nature_operation === NatureOperation::DISTRIBUTION_CLIENT
            ? 'Distributions/Show'
            : 'Ventes/Show';

        return Inertia::render($component, [
            'historiques' => $historiques,
            'activites' => $activites,
            'commande' => [
                'id' => $commande->id,
                'reference' => $commande->reference,
                'statut' => $commande->statut?->value,
                'statut_label' => $commande->statut_label,
                'statut_color' => $commande->statut?->color(),
                'total_commande' => (float) $commande->total_commande,
                'mode_tarification_snapshot' => $commande->mode_tarification_snapshot?->value,
                'mode_tarification_label' => $commande->mode_tarification_snapshot?->label(),
                'commission_eligible_snapshot' => (bool) $commande->commission_eligible_snapshot,
                'nature_operation' => $commande->nature_operation?->value,
                'nature_operation_label' => $commande->nature_operation?->label(),
                'vehicule_nom' => $commande->vehicule?->nom_vehicule,
                'vehicule_detail' => $vehicule ? [
                    'nom' => $vehicule->nom_vehicule,
                    'immatriculation' => $vehicule->immatriculation,
                    'type' => $vehicule->typeVehicule?->nom,
                    'capacites' => $this->vehiculeCapaciteService->capacitesParCategorieAvecNoms($vehicule),
                    'proprietaire_nom' => $vehicule->proprietaire
                        ? trim($vehicule->proprietaire->prenom.' '.$vehicule->proprietaire->nom)
                        : null,
                    'proprietaire_telephone' => $vehicule->proprietaire?->telephone,
                    'proprietaire_code_phone_pays' => $vehicule->proprietaire?->code_phone_pays,
                ] : null,
                'livreur_nom' => $chauffeur?->libelleAffichage(),
                'livreur_telephone' => $chauffeur?->telephone,
                'equipe_detail' => $equipe ? [
                    'nom' => $vehicule->nom_vehicule,
                    'taux_commission_proprietaire' => $equipe->taux_commission_proprietaire !== null
                        ? (float) $equipe->taux_commission_proprietaire
                        : null,
                    'chauffeur' => $chauffeur ? [
                        'nom' => $chauffeur->libelleAffichage(),
                        'telephone' => $chauffeur->telephone,
                    ] : null,
                    'convoyeurs' => $convoyeurs->map(fn ($l) => [
                        'nom' => $l->libelleAffichage(),
                        'telephone' => $l->telephone,
                    ])->values(),
                ] : null,
                'client_nom' => $commande->client?->nom_complet,
                'client_detail' => $commande->client ? [
                    'nom' => $commande->client->nom_complet,
                    'telephone' => $commande->client->telephone,
                    'code_phone_pays' => $commande->client->code_phone_pays,
                    'ville' => $commande->client->ville,
                    'adresse' => $commande->client->adresse,
                    'cashback_eligible' => (bool) $commande->client->cashback_eligible,
                ] : null,
                'site_nom' => $commande->site?->nom,
                'motif_annulation' => $commande->motif_annulation,
                'annulee_at' => $commande->annulee_at?->toISOString(),
                'a_charger_at' => $commande->a_charger_at?->format(self::DATE_DISPLAY_FORMAT),
                'chargement_demarre_at' => $commande->chargement_demarre_at?->format(self::DATE_DISPLAY_FORMAT),
                'chargement_valide_at' => $commande->chargement_valide_at?->format(self::DATE_DISPLAY_FORMAT),
                'livree_at' => $commande->livree_at?->format(self::DATE_DISPLAY_FORMAT),
                'reception_validee_at' => $commande->reception_validee_at?->format(self::DATE_DISPLAY_FORMAT),
                'closed_at' => $commande->closed_at?->format(self::DATE_DISPLAY_FORMAT),
                'is_brouillon' => $commande->isBrouillon(),
                'is_a_charger' => $commande->isACharger(),
                'is_chargement_en_cours' => $commande->isChargementEnCours(),
                'is_livraison_en_cours' => $commande->isLivraisonEnCours(),
                'is_livree' => $commande->isLivree(),
                'is_facturation' => $commande->isFacturation(),
                'is_cloturee' => $commande->isCloturee(),
                'is_annulee' => $commande->isAnnulee(),
                'can_modifier' => $commande->isBrouillon() && $user->can('update', $commande),
                'can_confirmer' => $commande->isBrouillon() && $user->can('confirmer', $commande),
                'can_demarrer_chargement' => $commande->isACharger() && $user->can('demarrerChargement', $commande),
                'can_valider_chargement' => $commande->isChargementEnCours() && $user->can('validerChargement', $commande),
                'can_valider_reception' => $commande->isLivraisonEnCours() && $user->can('validerReceptionDistribution', $commande),
                'can_annuler' => $commande->statut->isAnnulable()
                    && (! $facture || (float) $facture->montant_encaisse === 0.0)
                    && $user->can('annuler', $commande),
                'can_encaisser' => $facture && ! $facture->isAnnulee()
                    && (float) $facture->montant_restant > 0
                    && $commande->isEncaissable()
                    && $user->can('update', $commande),
                'created_at' => $commande->created_at?->format(self::DATE_DISPLAY_FORMAT),
                'created_by' => $commande->createdBy?->name,
                'lignes' => $lignes,
            ],
            'facture' => $facture ? [
                'id' => $facture->id,
                'reference' => $facture->reference,
                'montant_net' => (float) $facture->montant_net,
                'montant_encaisse' => (float) $facture->montant_encaisse,
                'montant_restant' => (float) $facture->montant_restant,
                'statut' => $facture->statut_facture?->value,
                'statut_label' => $facture->statut_label,
                'encaissements' => $facture->encaissements->map(fn ($e) => [
                    'id' => $e->id,
                    'montant' => (float) $e->montant,
                    'date_encaissement' => $e->date_encaissement?->format(self::DATE_DISPLAY_FORMAT),
                    'heure' => $e->created_at?->format('H:i'),
                    'mode_paiement' => $e->mode_paiement?->value,
                    'mode_paiement_label' => $e->mode_paiement?->label(),
                    'note' => $e->note,
                    'created_by' => $e->creator?->name,
                ])->values(),
            ] : null,
            'commission_statut' => $this->getCommissionStatutGlobal($commande),
            'commission_generation_statut' => $this->getCommissionGenerationStatut($commande),
        ]);
    }

    // ── Edit ─────────────────────────────────────────────────────────────────

    public function edit(CommandeVente $vente): Response
    {
        $this->authorize('update', $vente);
        abort_if(! $vente->isBrouillon(), 403, 'Seule une commande en brouillon peut être modifiée.');

        $orgId = auth()->user()->organization_id;
        $vente->load(['lignes.variante']);

        return Inertia::render('Ventes/Edit', [
            'commande' => [
                'id' => $vente->id,
                'reference' => $vente->reference,
                'vehicule_id' => $vente->vehicule_id,
                'client_id' => $vente->client_id,
                'client_vehicule_id' => $vente->client_vehicule_id,
                'lignes' => $vente->lignes->map(fn ($l) => [
                    // Bridge Phase 3 : le formulaire actuel ne sélectionne qu'un produit
                    // (pas de sélecteur de variante), on retrouve donc le produit parent.
                    'produit_id' => $l->variante?->produit_id,
                    'variante_id' => $l->variante_id,
                    'qte' => (int) $l->quantite_demandee,
                    'prix_vente' => (float) $l->prix_vente_snapshot,
                ]),
            ],
            'produits' => $this->produitsActifs($orgId),
            'vehicules' => $this->vehiculesActifs($orgId),
            'clients' => $this->clientsActifs($orgId),
            'user_site' => $this->getUserSite(),
            'can_modifier_qte' => auth()->user()->can('ventes.qte.update'),
            'autoriser_saisie_dessous_qte_max' => Parametre::isVentesAutorisationSaisieDessousQteMax($orgId),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, CommandeVente $vente): RedirectResponse
    {
        $this->authorize('update', $vente);
        abort_if(! $vente->isBrouillon(), 403, 'Seule une commande en brouillon peut être modifiée.');

        $data = $request->validate($this->commandeValidationRules(), $this->commandeValidationMessages());

        $this->ensureVehiculeOrClientSelected($data);
        // nature_operation est figée à la création (jamais recalculée ici, cf. NatureOperation) —
        // mais si la commande est déjà une distribution client, un changement de véhicule sur ce
        // brouillon doit continuer à respecter exactement les mêmes règles qu'à la création
        // (organisation, actif, logistique, livreur assigné), sous peine de permettre de
        // contourner la contrainte simplement en éditant plutôt qu'en créant.
        $vehiculePourValidation = $this->resolveVehiculeAvecEquipe($data['vehicule_id'] ?? null, $vente->organization_id);
        $this->ensureNatureOperationCoherente($vente->nature_operation, $data['vehicule_id'] ?? null, $vehiculePourValidation);
        $this->ensureQuantiteMatchesVehiculeCapacity($data);
        $client = $this->resolveClientForTarification($data['client_id'] ?? null);
        $this->enforcePrixVentePolicy($data, $vente, $client);

        $vente->load(['lignes.variante.produit', 'vehicule', 'client']);
        $oldSnapshot = $this->commandeSnapshot($vente);

        $context = VehiculeCommandeContextResolver::resolve($data['vehicule_id'] ?? null, $data['client_id'] ?? null, $vente->nature_operation);
        [$lignesData, $totalCommande] = $this->buildLignesDataAndTotal($data['lignes'], $context->modeTarification, $context->categorieTarifaireVehicule, $client);

        // Le site ne change jamais lors d'une modification de brouillon (pas de champ site_id
        // dans commandeValidationRules()) : on contrôle donc contre le site déjà porté par la
        // commande, jamais celui de l'utilisateur qui modifie (qui pourrait être différent).
        $this->assertStockDisponiblePourLignes($vente->organization_id, $vente->site_id, $lignesData);

        $vente->update([
            'vehicule_id' => $data['vehicule_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'client_vehicule_id' => $data['client_vehicule_id'] ?? null,
            'total_commande' => $totalCommande,
            'mode_tarification_snapshot' => $context->modeTarification->value,
            'commission_eligible_snapshot' => $context->commissionEligible,
        ]);

        $vente->lignes()->delete();
        foreach ($lignesData as $ligneDatum) {
            $vente->lignes()->create($ligneDatum);
        }

        $vente->refresh()->load(['lignes.variante.produit', 'vehicule', 'client']);
        $newSnapshot = $this->commandeSnapshot($vente);

        [$oldDiff, $newDiff] = $this->auditService->diff($oldSnapshot, $newSnapshot);
        if ($oldDiff !== null || $newDiff !== null) {
            $this->auditService->record($vente, AuditEvent::UPDATED, auth()->user(), $oldDiff, $newDiff);
        }

        return redirect()->route('ventes.show', $vente)->with('success', 'Commande mise à jour.');
    }

    // ── Valider : BROUILLON → A_CHARGER ──────────────────────────────────────

    public function valider(CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('confirmer', $commande_vente);

        $oldStatut = $commande_vente->statut->value;

        try {
            CommandeVenteService::confirmer($commande_vente);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $this->auditService->record(
            $commande_vente,
            AuditEvent::VALIDATED,
            auth()->user(),
            ['statut' => $oldStatut],
            ['statut' => StatutCommandeVente::A_CHARGER->value],
        );

        CommandeVenteActiviteService::log($commande_vente, 'confirmee');

        if ($commande_vente->vehicule_id) {
            NotifierLivreursCommandeVenteJob::dispatch($commande_vente->id, $commande_vente->reference);
        }

        return back()->with('success', 'Commande confirmée. En attente de chargement.');
    }

    // ── Annuler ───────────────────────────────────────────────────────────────

    public function annuler(Request $request, CommandeVente $commande_vente): RedirectResponse
    {
        if (auth()->user()->cannot('annuler', $commande_vente)) {
            abort(403, "Vous n'êtes pas autorisé à annuler cette commande.");
        }

        $validCodes = implode(',', MotifAnnulation::validValues());

        $data = $request->validate([
            'motif_annulation_code' => ['required', 'string', "in:{$validCodes}"],
            'motif_annulation_detail' => ['nullable', 'string', 'max:2000', 'required_if:motif_annulation_code,autre'],
        ], [
            'motif_annulation_code.required' => "Le motif d'annulation est obligatoire.",
            'motif_annulation_code.in' => 'Le motif sélectionné est invalide.',
            'motif_annulation_detail.required_if' => "Veuillez préciser la raison de l'annulation.",
            'motif_annulation_detail.max' => 'La précision ne peut pas dépasser 2000 caractères.',
        ]);

        $motif = MotifAnnulation::from($data['motif_annulation_code'])
            ->toMotifString($data['motif_annulation_detail'] ?? '');

        $oldStatut = $commande_vente->statut->value;

        try {
            CommandeVenteService::annuler($commande_vente, $motif);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $this->auditService->record(
            $commande_vente,
            AuditEvent::CANCELLED,
            auth()->user(),
            ['statut' => $oldStatut, 'motif_annulation' => null],
            ['statut' => StatutCommandeVente::ANNULEE->value, 'motif_annulation' => $motif],
        );

        CommandeVenteActiviteService::log($commande_vente, 'annulee', ['motif' => $motif]);

        return back()->with('success', 'Commande annulée.');
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function destroy(CommandeVente $vente): RedirectResponse
    {
        $this->authorize('delete', $vente);
        abort_unless($vente->isAnnulee(), 403, 'Seules les commandes annulées peuvent être supprimées.');

        $this->auditService->record(
            $vente,
            AuditEvent::DELETED,
            auth()->user(),
            ['reference' => $vente->reference, 'statut' => $vente->statut->value],
            null,
        );

        $vente->delete();

        return redirect()->route('ventes.index')->with('success', 'Commande supprimée.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Seul appelant de SolvabiliteService::enforcerOuEchouer() côté back-office — voir
     * PdvCheckoutService::checkout() pour l'appelant équivalent côté PDV, sur exactement le
     * même service (jamais de calcul dupliqué).
     */
    private function enforceImpayesBlocking(array $data, string $orgId): void
    {
        $this->solvabiliteService->enforcerOuEchouer(
            $orgId,
            $data['vehicule_id'] ?? null,
            $data['client_id'] ?? null,
        );
    }

    private function getCommissionStatutGlobal(CommandeVente $commande): ?array
    {
        $commissions = $commande->commissions;
        if ($commissions->isEmpty()) {
            return null;
        }

        if ($commissions->every(fn ($c) => $c->statut === StatutCommission::CREEE)) {
            return ['value' => 'creee', 'label' => 'Créée'];
        }
        if ($commissions->every(fn ($c) => $c->statut === StatutCommission::PAYE)) {
            return ['value' => 'paye', 'label' => 'Payée'];
        }
        if ($commissions->some(fn ($c) => $c->statut === StatutCommission::PAYE || $c->statut === StatutCommission::PARTIEL)) {
            return ['value' => 'partiel', 'label' => 'Partiellement payée'];
        }

        return ['value' => 'impaye', 'label' => 'Impayée'];
    }

    /**
     * Statut de la DERNIÈRE tentative de génération de commission (distinct de
     * commission_statut, qui ne reflète que le paiement de commissions déjà
     * générées avec succès) — retourne null tant que rien n'est en anomalie :
     * pas éligible, aucune tentative encore, ou dernière tentative réussie.
     * Ne remonte que le cas ERREUR ("à régulariser"), seul cas nécessitant une
     * alerte visible (cf. incident CMD-230826-004, où cet état n'était visible
     * nulle part dans l'UI faute d'être exposé ici).
     */
    private function getCommissionGenerationStatut(CommandeVente $commande): ?array
    {
        if (! $commande->commission_eligible_snapshot) {
            return null;
        }

        // Route par nature_operation — jamais un CODE_VENTE codé en dur (correctif du
        // 30/08/2026 : une commande distribution_client ne remontait jamais son état "à
        // régulariser", puisque sa CommissionGenerationAttempt est rattachée à un autre
        // processus que vente). Distribution → CODE_LOGISTIQUE_TRANSFERT depuis le 01/09/2026
        // (cf. CommissionEnveloppeGenerator::genererPourCommandeVente()), jamais
        // CODE_DISTRIBUTION_CLIENT — sinon cette recherche viserait un processus qui ne reçoit
        // plus aucune tentative depuis cette date.
        $processusCode = $commande->nature_operation === NatureOperation::DISTRIBUTION_CLIENT
            ? CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT
            : CommissionProcessus::CODE_VENTE;

        $processusId = CommissionProcessus::where('organization_id', $commande->organization_id)
            ->where('code', $processusCode)
            ->value('id');

        if (! $processusId) {
            return null;
        }

        $derniere = CommissionGenerationAttempt::where('source_type', CommandeVente::class)
            ->where('source_id', $commande->id)
            ->where('processus_id', $processusId)
            ->latest('created_at')
            ->first();

        if (! $derniere || $derniere->statut !== CommissionGenerationStatut::ERREUR) {
            return null;
        }

        return [
            'value' => 'erreur',
            'label' => $derniere->statut->label(),
            'motif' => $derniere->motif_erreur,
        ];
    }

    /**
     * Relance manuelle après un échec de génération de commission ("à
     * régulariser") — rejoue le mécanisme officiel (CommissionEnveloppeGenerator),
     * jamais un recalcul ad hoc. Idempotent par construction : si une enveloppe
     * existe déjà (généré entre-temps), l'appel est un no-op silencieux.
     */
    public function relancerCommissions(CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('update', $commande_vente);

        CommissionEnveloppeGenerator::genererPourCommandeVente(
            $commande_vente,
            CommissionGenerationDeclenchePar::UTILISATEUR,
            auth()->id(),
        );

        $statut = $this->getCommissionGenerationStatut($commande_vente);

        $commande_vente->cloturerSiComplete();

        if ($statut !== null && $statut['value'] === 'erreur') {
            return redirect()->route('ventes.show', $commande_vente)->withErrors([
                'commissions' => "La génération a de nouveau échoué : {$statut['motif']}",
            ]);
        }

        return redirect()->route('ventes.show', $commande_vente)->with('success', 'Commissions générées avec succès.');
    }

    private function mapCommandeForIndex(CommandeVente $c, mixed $user): array
    {
        return [
            'id' => $c->id,
            'reference' => $c->reference,
            'statut' => $c->statut?->value,
            'statut_label' => $c->statut_label,
            'statut_color' => $c->statut?->color(),
            'nature_operation' => $c->nature_operation?->value,
            'total_commande' => (float) $c->total_commande,
            'vehicule_nom' => $c->vehicule?->nom_vehicule,
            'vehicule_immatriculation' => $c->vehicule?->immatriculation,
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
                'date_encaissement' => $e->date_encaissement?->format(self::DATE_DISPLAY_FORMAT),
                'heure' => $e->created_at?->format('H:i'),
                'mode_paiement_label' => $e->mode_paiement?->label(),
                'created_by' => $e->creator?->name,
            ])->values() : [],
            'created_at' => $c->created_at?->format(self::DATE_DISPLAY_FORMAT),
            'is_annulee' => $c->isAnnulee(),
            'is_brouillon' => $c->isBrouillon(),
            'is_facturation' => $c->isFacturation(),
            'can_modifier' => $c->isBrouillon() && $user->can('update', $c),
            'can_confirmer' => $c->isBrouillon() && $user->can('confirmer', $c),
            'can_annuler' => $c->statut->isAnnulable()
                && (! $c->facture || (float) $c->facture->montant_encaisse === 0.0)
                && $user->can('annuler', $c),
        ];
    }

    private function commandeValidationRules(): array
    {
        return [
            'vehicule_id' => 'nullable|exists:vehicules,id',
            'client_id' => 'nullable|exists:clients,id',
            'nature_operation' => ['nullable', Rule::in(NatureOperation::values())],
            // Véhicule partenaire facultatif — jamais un substitut à vehicule_id (flotte gérée),
            // cf. ClientVehicle. Doit appartenir au client sélectionné.
            'client_vehicule_id' => [
                'nullable',
                Rule::exists('client_vehicules', 'id')->where(function ($q) {
                    $q->where('client_id', request()->input('client_id'));
                }),
            ],
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => 'required|exists:produits,id',
            // Optionnel : le formulaire actuel ne sélectionne qu'un produit (pas encore de
            // sélecteur de variante — Phase 3). resolveVariante() retombe sur la variante
            // par défaut du produit si absent.
            'lignes.*.variante_id' => 'nullable|exists:produit_variantes,id',
            'lignes.*.qte' => 'required|integer|min:1',
            'lignes.*.prix_vente' => 'required|numeric|min:0',
        ];
    }

    private function commandeValidationMessages(): array
    {
        return [
            'lignes.required' => self::LIGNES_REQUIRED_MESSAGE,
            'lignes.min' => self::LIGNES_REQUIRED_MESSAGE,
            'lignes.*.produit_id.required' => 'Le produit est obligatoire pour chaque ligne.',
            'lignes.*.produit_id.exists' => 'Le produit sélectionné est introuvable.',
            'lignes.*.qte.required' => 'La quantité est obligatoire pour chaque ligne.',
            'lignes.*.qte.min' => 'La quantité doit être supérieure à 0.',
            'lignes.*.prix_vente.required' => 'Le prix de vente est obligatoire pour chaque ligne.',
            'lignes.*.prix_vente.min' => 'Le prix de vente ne peut pas être négatif.',
        ];
    }

    private function ensureVehiculeOrClientSelected(array $data): void
    {
        if (! empty($data['vehicule_id']) || ! empty($data['client_id'])) {
            return;
        }

        throw ValidationException::withMessages([
            'vehicule_id' => 'Veuillez sélectionner un véhicule ou un client.',
            'client_id' => 'Veuillez sélectionner un véhicule ou un client.',
        ]);
    }

    /**
     * Charge le véhicule une seule fois par requête (store/update), scopé à l'organisation
     * courante — jamais l'ID brut non vérifié — avec son équipe et son chauffeur actif
     * eager-chargés. Réutilisé par la dérivation de nature_operation, sa validation de
     * cohérence, et le pré-contrôle de partage commission : jamais trois requêtes séparées.
     */
    private function resolveVehiculeAvecEquipe(?string $vehiculeId, string $orgId): ?Vehicule
    {
        if (empty($vehiculeId)) {
            return null;
        }

        return Vehicule::query()
            ->with(['equipe.livreurs' => fn ($q) => $q->wherePivot('role', 'chauffeur')])
            ->where('organization_id', $orgId)
            ->find($vehiculeId);
    }

    /**
     * Nature effectivement retenue pour la commande — explicite si soumise, dérivée sinon
     * (NatureOperation::deriverParDefaut(), seule source de vérité). Calculée une seule fois,
     * puis réutilisée pour la validation de cohérence ET la persistance : jamais un second appel
     * à deriverParDefaut() qui pourrait diverger du premier.
     */
    private function resoudreNatureOperation(array $data, ?Client $client, ?Vehicule $vehicule): NatureOperation
    {
        return isset($data['nature_operation'])
            ? NatureOperation::from($data['nature_operation'])
            : NatureOperation::deriverParDefaut($client?->type, $vehicule);
    }

    /**
     * Backend, source de vérité — le frontend filtre déjà la liste des véhicules et désactive
     * l'option quand elle n'est pas disponible, mais la règle métier ne doit jamais reposer
     * uniquement sur le formulaire (contournement possible via API/requête forgée). Révisé le
     * 31/08/2026 : vérifiait auparavant seulement la présence d'un véhicule, jamais son usage
     * autorisé ni la présence d'un livreur assigné.
     *
     * $vehicule doit déjà être scopé à l'organisation courante (cf. resolveVehiculeAvecEquipe())
     * — un véhicule non trouvé ($vehicule === null alors que $vehiculeId est renseigné) signifie
     * donc soit un id inexistant, soit un véhicule d'une autre organisation : les deux cas
     * doivent être rejetés de la même façon, jamais une fuite d'information sur l'existence
     * réelle du véhicule dans une autre organisation.
     */
    private function ensureNatureOperationCoherente(NatureOperation $natureOperation, ?string $vehiculeId, ?Vehicule $vehicule): void
    {
        if ($natureOperation !== NatureOperation::DISTRIBUTION_CLIENT) {
            return;
        }

        if (empty($vehiculeId)) {
            throw ValidationException::withMessages([
                'nature_operation' => 'Une distribution client nécessite un véhicule de livraison.',
            ]);
        }

        if (! $vehicule) {
            throw ValidationException::withMessages([
                'vehicule_id' => 'Ce véhicule est introuvable pour votre organisation.',
            ]);
        }

        if (! $vehicule->is_active) {
            throw ValidationException::withMessages([
                'vehicule_id' => "Ce véhicule n'est plus actif.",
            ]);
        }

        if (! $vehicule->livraison_logistique) {
            throw ValidationException::withMessages([
                'vehicule_id' => "Ce véhicule n'est pas autorisé pour la distribution (usage logistique requis).",
            ]);
        }

        $aUnLivreurActif = $vehicule->equipe?->is_active
            && $vehicule->equipe->livreurs->contains(fn ($l) => $l->is_active);

        if (! $aUnLivreurActif) {
            throw ValidationException::withMessages([
                'vehicule_id' => "Ce véhicule n'a aucun livreur actif assigné — une distribution nécessite un livreur.",
            ]);
        }
    }

    private function ensureQuantiteMatchesVehiculeCapacity(array $data): void
    {
        if (empty($data['vehicule_id'])) {
            return;
        }

        $vehicule = Vehicule::query()->find($data['vehicule_id']);
        if (! $vehicule) {
            return;
        }

        $orgId = auth()->user()->organization_id;

        $this->vehiculeCapaciteService->verifier(
            $vehicule,
            $data['lignes'] ?? [],
            'qte',
            ! Parametre::isVentesAutorisationSaisieDessousQteMax($orgId),
        );
    }

    /**
     * Garde-fou préventif — jamais un remplacement du filet de sécurité de la génération
     * (CommissionEnveloppeGenerator, différée au déclencheur configuré par l'organisation, cf.
     * CommissionTriggerService) : réduit le risque qu'une commande apparaisse "payée" mais reste
     * bloquée "à régulariser" faute de partage Livreur configuré pour une catégorie vendue (cf.
     * incident CMD-300826-007, 30/08/2026). La configuration de partage peut encore changer entre
     * cette création et la génération réelle — ce contrôle réduit le risque, il ne l'élimine pas.
     *
     * Hors périmètre volontairement : véhicule sans équipe de livraison du tout (erreur distincte,
     * déjà portée par le générateur) et véhicule non éligible pour l'usage réellement concerné
     * (commission_eligible_snapshot resterait false, la génération ne tente jamais de résoudre le
     * partage Livreur pour ce véhicule) — la vérification d'usage autorisé (livraison_vente pour
     * une vente, livraison_logistique pour une distribution, révisé le 31/08/2026) reflète
     * exactement celle de VehiculeCommandeContextResolver::resolve().
     *
     * $vehicule et $natureOperation doivent être ceux déjà résolus par resolveVehiculeAvecEquipe()/
     * resoudreNatureOperation() — jamais un second calcul indépendant qui pourrait diverger.
     */
    private function ensurePartageLivraisonCategorieConfigure(NatureOperation $natureOperation, ?Vehicule $vehicule, array $lignes): void
    {
        if (! $vehicule || ! $vehicule->equipe) {
            return;
        }

        $usageAutorise = $natureOperation === NatureOperation::DISTRIBUTION_CLIENT
            ? (bool) $vehicule->livraison_logistique
            : (bool) ($vehicule->livraison_vente ?? true);

        if (! $usageAutorise) {
            return;
        }

        // Distribution → CODE_LOGISTIQUE_TRANSFERT depuis le 01/09/2026, jamais
        // CODE_DISTRIBUTION_CLIENT — doit refléter exactement le routage réel du générateur
        // (CommissionEnveloppeGenerator::genererPourCommandeVente()), sinon ce garde-fou
        // préventif validerait un partage qui ne sera jamais celui réellement consommé.
        $processusCode = $natureOperation === NatureOperation::DISTRIBUTION_CLIENT
            ? CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT
            : CommissionProcessus::CODE_VENTE;

        $categorieIds = CommissionPartageLivraisonCategorieChecker::categorieIdsDepuisLignes($lignes);

        $manquantes = CommissionPartageLivraisonCategorieChecker::categoriesManquantes(
            auth()->user()->organization_id,
            $vehicule->equipe->id,
            $processusCode,
            $vehicule->type_vehicule_id,
            $categorieIds,
            Carbon::today(),
        );

        if ($manquantes->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'vehicule_id' => sprintf(
                'Le véhicule %s n\'a pas de partage de commission configuré pour le processus « %s » sur : %s. Configurez la répartition de l\'équipe avant de continuer.',
                $vehicule->nom_vehicule,
                $natureOperation->label(),
                $manquantes->pluck('nom')->implode(', '),
            ),
        ]);
    }

    /**
     * Résout le client une seule fois par requête (store/update), réutilisé par
     * enforcePrixVentePolicy() ET buildLignesDataAndTotal() — jamais un second aller-retour DB
     * pour la même commande. Sélection minimale ('id', 'type') : c'est tout ce dont
     * PrixVenteNatureResolver a besoin.
     */
    private function resolveClientForTarification(?string $clientId): ?Client
    {
        return $clientId ? Client::query()->select(['id', 'type'])->find($clientId) : null;
    }

    private function enforcePrixVentePolicy(array $data, ?CommandeVente $commande, ?Client $client): void
    {
        if (auth()->user()->can(self::UNIT_PRICE_UPDATE_PERMISSION)) {
            return;
        }

        $lignes = collect($data['lignes'] ?? []);
        if ($lignes->isEmpty()) {
            return;
        }

        $existingPrixParVariante = $this->existingPrixVenteByVariante($commande);

        foreach ($data['lignes'] as $index => $ligne) {
            $variante = $this->resolveVariante($ligne);

            // Ligne fabricable avec client : le prix effectivement facturé vient de
            // PrixVenteNatureResolver (cf. buildLignesDataAndTotal()), pas de ce qui est soumis
            // ici — aucune valeur reçue n'est donc jamais réellement utilisée pour cette ligne,
            // ce contrôle anti-manipulation n'a plus d'objet.
            if (PrixVenteNatureResolver::estFabricable($variante) && $client) {
                continue;
            }

            $prixRecu = (float) ($ligne['prix_vente'] ?? 0);
            $prixAttendu = $existingPrixParVariante[$variante->id] ?? (float) ($variante->prix_vente ?? $prixRecu);

            if (abs($prixRecu - $prixAttendu) > 0.00001) {
                throw ValidationException::withMessages([
                    "lignes.{$index}.prix_vente" => 'Vous n\'etes pas autorisé à modifier le prix unitaire.',
                ]);
            }
        }
    }

    private function existingPrixVenteByVariante(?CommandeVente $commande): array
    {
        if (! $commande) {
            return [];
        }

        $commande->loadMissing('lignes');

        return $commande->lignes
            ->groupBy('variante_id')
            ->map(fn ($lignes): float => (float) $lignes->first()->prix_vente_snapshot)
            ->toArray();
    }

    private function buildLignesDataAndTotal(array $lignes, ModeTarification $mode, ?CategorieTarifaireVehicule $categorieTarifaire = null, ?Client $client = null): array
    {
        $lignesData = [];
        $totalCommande = 0;

        foreach ($lignes as $ligne) {
            $variante = $this->resolveVariante($ligne);
            $produit = $variante->produit;
            $qte = (int) $ligne['qte'];
            // Fabricable + client : le prix par nature de client (Externe/Revendeur/
            // Distributeur) remplace le prix de vente saisi/existant — jamais l'inverse (cf.
            // enforcePrixVentePolicy() qui n'a alors plus rien à valider pour cette ligne) — et
            // gouverne SEUL le total de cette ligne, sans passer par le mode de tarification
            // véhicule/client (qui basculerait sinon un client Externe entier sur prix_usine,
            // ignorant le prix_externe qu'on vient de résoudre). Produit non-fabricable ou
            // aucun client : comportement historique inchangé (mode global).
            $ligneFabricablePourClient = PrixVenteNatureResolver::estFabricable($variante) && $client;
            $prixVente = $ligneFabricablePourClient
                ? (float) PrixVenteNatureResolver::resolve($variante, $client)
                : (float) $ligne['prix_vente'];
            $prixUsine = (float) PrixUsineResolver::resolve($variante, $categorieTarifaire);
            $appliquerPrixVente = $ligneFabricablePourClient || $mode === ModeTarification::PRIX_VENTE;
            $totalLigne = $qte * ($appliquerPrixVente ? $prixVente : $prixUsine);
            $prixOrigine = $ligneFabricablePourClient
                ? PrixVenteNatureResolver::resolveOrigine($variante, $client)
                : ($appliquerPrixVente ? PrixOrigine::VENTE : PrixOrigine::USINE);

            $lignesData[] = [
                'variante_id' => $variante->id,
                'quantite_demandee' => $qte,
                'prix_usine_snapshot' => $prixUsine,
                'prix_vente_snapshot' => $prixVente,
                'prix_origine_snapshot' => $prixOrigine->value,
                'total_ligne' => $totalLigne,
                'libelle_snapshot' => $this->libelleSnapshot($produit, $variante),
            ];

            $totalCommande += $totalLigne;
        }

        return [$lignesData, $totalCommande];
    }

    /**
     * Résout la variante réellement vendue à partir d'une ligne saisie. Le formulaire actuel
     * ne propose qu'un sélecteur de produit (pas encore de sélecteur de variante — Phase 3) :
     * si le produit n'a qu'une seule variante (cas normal, produit "simple"), on la prend
     * directement ; sinon on exige que variante_id soit explicitement fourni plutôt que de
     * deviner laquelle vendre.
     */
    private function resolveVariante(array $ligne): ProduitVariante
    {
        if (! empty($ligne['variante_id'])) {
            return ProduitVariante::with('produit.produitType')->findOrFail($ligne['variante_id']);
        }

        $produit = Produit::with(['variantes', 'produitType'])->findOrFail($ligne['produit_id']);

        if ($produit->variantes->count() === 1) {
            return $produit->variantes->first();
        }

        if ($produit->variantes->count() > 1) {
            throw ValidationException::withMessages([
                'lignes' => "Le produit « {$produit->nom} » a plusieurs déclinaisons — précisez la variante à vendre.",
            ]);
        }

        throw ValidationException::withMessages([
            'lignes' => "Le produit « {$produit->nom} » n'a aucune variante disponible.",
        ]);
    }

    private function libelleSnapshot(Produit $produit, ProduitVariante $variante): string
    {
        return $variante->libelle !== '' ? "{$produit->nom} — {$variante->libelle}" : $produit->nom;
    }

    private function commandeSnapshot(CommandeVente $commande): array
    {
        return [
            'vehicule_id' => $commande->vehicule_id,
            'vehicule_nom' => $commande->vehicule?->nom_vehicule,
            'client_id' => $commande->client_id,
            'client_nom' => $commande->client?->nom_complet,
            'total_commande' => (float) $commande->total_commande,
            'mode_tarification_snapshot' => $commande->mode_tarification_snapshot?->value,
            'commission_eligible_snapshot' => (bool) $commande->commission_eligible_snapshot,
            'nature_operation' => $commande->nature_operation?->value,
            'statut' => $commande->statut?->value,
            'lignes' => $commande->lignes->map(fn ($l) => [
                'variante_id' => $l->variante_id,
                'produit_nom' => $l->libelle_snapshot ?? $l->variante?->produit?->nom,
                'quantite_demandee' => (int) $l->quantite_demandee,
                'prix_vente_snapshot' => (float) $l->prix_vente_snapshot,
                'total_ligne' => (float) $l->total_ligne,
            ])->values()->all(),
        ];
    }

    private function getUserSite(): array
    {
        $site = $this->getUserSiteModel();

        return [
            'id' => $site->id,
            'nom' => $site->nom,
            'label' => ($site->type?->label() ?? '').' de '.$site->nom,
        ];
    }

    private function getUserSiteModel(): mixed
    {
        $site = auth()->user()
            ->sites()
            ->wherePivot('is_default', true)
            ->first(['sites.id', 'sites.nom', 'sites.type'])
            ?? auth()->user()->sites()->first(['sites.id', 'sites.nom', 'sites.type']);

        abort_if(! $site, 403, "Votre compte n'est rattaché à aucun site. Contactez votre administrateur.");

        return $site;
    }

    /**
     * Variante non-bloquante de getUserSiteModel(), pour index() : cette page reste
     * consultable même par un utilisateur sans aucun site attaché (contrairement à
     * create()/store(), qui abortent) — null désactive simplement le calcul du blocage du
     * bouton « Nouvelle commande » plutôt que de faire 403 toute la liste des commandes.
     */
    private function getUserSiteIdOrNull(): ?string
    {
        $user = auth()->user();

        return $user->sites()->wherePivot('is_default', true)->value('sites.id')
            ?? $user->sites()->value('sites.id');
    }

    /**
     * Bloque la création d'une nouvelle commande vente quand la politique globale interdit la
     * vente sans stock ET que le site personnel de l'utilisateur n'a absolument aucun stock
     * vendable (cf. CommandeVenteService::siteAutoriseNouvelleCommande()). Appelé par create()
     * (accès direct à la page) et store() (POST direct) — même contrôle, jamais dupliqué en
     * logique, pour que désactiver le bouton côté Ventes/Index ne soit jamais la seule
     * protection. Ne renvoie jamais une page 403 : un accès direct malgré le blocage redirige
     * vers la liste des ventes avec un flash 'error', affiché en toast top-right côté
     * Ventes/Index.vue (règle projet : jamais de switch de position pour ce Toast).
     */
    private function redirectSiCreationBloquee(string $orgId, string $siteId): ?RedirectResponse
    {
        if (CommandeVenteService::siteAutoriseNouvelleCommande($orgId, $siteId)) {
            return null;
        }

        return redirect()->route('ventes.index')->with('error', self::MESSAGE_BLOCAGE_TOAST);
    }

    /**
     * Contrôle de disponibilité au moment de CRÉER ou MODIFIER une commande (24/08/2026) —
     * avant ce correctif, une commande pouvait être créée avec une quantité supérieure au
     * stock, le seul contrôle existant intervenait au chargement (cf. CommandeVenteService::
     * checkDisponibiliteStock()). Délègue entièrement à CommandeVenteService::
     * verifierDisponibiliteLignes() — jamais de logique dupliquée ici, ce contrôleur ne fait
     * que traduire le résultat en ValidationException affichée dans le formulaire. $lignesData
     * est le format déjà produit par buildLignesDataAndTotal() (variante_id résolu +
     * quantite_demandee), jamais recalculé.
     *
     * @param  array<int, array{variante_id: string, quantite_demandee: int}>  $lignesData
     *
     * @throws ValidationException si au moins une ligne dépasse le disponible
     */
    private function assertStockDisponiblePourLignes(string $orgId, string $siteId, array $lignesData): void
    {
        $errors = [];

        CommandeVenteService::verifierDisponibiliteLignes(
            $orgId,
            $siteId,
            array_map(fn (array $l) => ['variante_id' => $l['variante_id'], 'quantite' => $l['quantite_demandee']], $lignesData),
            $errors,
        );

        if (! empty($errors)) {
            throw ValidationException::withMessages(['lignes' => $errors]);
        }
    }

    /**
     * $siteId : quand fourni ET que la politique globale interdit la vente sans stock
     * (Parametre::isVentesAutoriseesSansStock() = false), un produit géré en stock est exclu
     * de la liste si sa variante par défaut n'a AUCUN stock disponible sur CE site précis —
     * jamais sur l'agrégat global du produit (décision produit du 24/08/2026 : un stock
     * ailleurs ne doit jamais rendre visible un produit indisponible ici). $siteId omis
     * (edit() d'un brouillon existant) = aucun filtrage par stock, pour ne jamais faire
     * disparaître de la liste une ligne déjà existante dont le stock serait depuis tombé à 0.
     * Le formulaire ne propose pour l'instant qu'un sélecteur de produit (pas de sélecteur de
     * variante — Phase 3) : on filtre/affiche donc sur la variante par défaut (ou la première).
     */
    private function produitsActifs(string $orgId, ?string $siteId = null): Collection
    {
        $autoriseVenteStockNegatif = Parametre::isVentesAutoriseesSansStock($orgId);

        $produits = Produit::where('organization_id', $orgId)
            ->where('statut', ProduitStatut::ACTIF)
            ->whereHas('produitType', fn ($q) => $q->where('vendable', true))
            ->with(['variantes', 'produitType'])
            ->orderBy('nom')
            ->get();

        $varianteIds = $produits->flatMap(fn (Produit $p) => $p->variantes->pluck('id'))->all();
        // Disponible = physique − engagé (StockReservationService, 25/08/2026) : un produit
        // entièrement engagé par des commandes vente confirmées ne doit plus apparaître comme
        // sélectionnable ici, même si son stock physique brut reste positif.
        $stocksParVariante = $siteId
            ? VarianteStock::where('site_id', $siteId)->whereIn('produit_variante_id', $varianteIds)->get(['produit_variante_id', 'qte_stock', 'qte_reservee'])->keyBy('produit_variante_id')
            : collect();

        return $produits
            ->map(function (Produit $p) use ($stocksParVariante, $autoriseVenteStockNegatif, $siteId) {
                $variante = $p->variantes->firstWhere('is_default', true) ?? $p->variantes->first();
                $gereStock = (bool) $p->produitType?->gere_stock;

                if ($siteId && $gereStock && ! $autoriseVenteStockNegatif) {
                    $stock = $stocksParVariante[$variante?->id] ?? null;
                    $disponible = $stock ? ((int) $stock->qte_stock - (int) $stock->qte_reservee) : 0;
                    if ($disponible <= 0) {
                        return null;
                    }
                }

                return [
                    'id' => $p->id,
                    'nom' => $p->nom,
                    'categorie_id' => $p->categorie_id,
                    'prix_vente' => (int) ($variante?->prix_vente ?? 0),
                    'prix_usine' => (int) ($variante?->prix_usine ?? 0),
                    // Tarification par nature de client (cf. PrixVenteNatureResolver) — pilote
                    // le recalcul live du "Prix appliqué" dans Ventes/Create.vue/Edit.vue dès
                    // qu'un client est sélectionné. Réservée aux produits fabricables.
                    'is_fabricable' => $p->produitType?->code === 'fabricable',
                    'prix_externe' => $variante?->prix_externe,
                    'prix_revendeur' => $variante?->prix_revendeur,
                    'prix_distributeur' => $variante?->prix_distributeur,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Véhicules sélectionnables pour une vente standard — inchangé par le chantier « distribution
     * client » du 31/08/2026 (cf. vehiculesLogistiques() pour le pool séparé, exclusif à la
     * distribution).
     */
    private function vehiculesActifs(string $orgId): Collection
    {
        return $this->vehiculesEligibles($orgId, fn ($q) => $q->livraisonVente());
    }

    /**
     * Véhicules sélectionnables pour une distribution client — autorisés pour l'usage logistique
     * (Vehicule::livraison_logistique = true), jamais les véhicules vente-only. Pool séparé de
     * vehiculesActifs() ci-dessus : un même véhicule peut apparaître dans les deux si son
     * organisation l'a explicitement autorisé pour les deux usages, mais jamais un véhicule
     * exclusivement vente n'apparaît ici, ni l'inverse.
     */
    private function vehiculesLogistiques(string $orgId): Collection
    {
        return $this->vehiculesEligibles($orgId, fn ($q) => $q->livraisonLogistique());
    }

    /** @param  callable(Builder): Builder  $scopeUsage */
    private function vehiculesEligibles(string $orgId, callable $scopeUsage): Collection
    {
        $query = Vehicule::with([
            'typeVehicule',
            'capacites.categorie',
            'equipe.livreurs' => fn ($q) => $q->wherePivot('role', 'chauffeur'),
            'equipe.membres.livreur',
        ])
            ->where('organization_id', $orgId)
            ->where('is_active', true);

        $scopeUsage($query);

        return $query
            ->orderBy('nom_vehicule')
            ->get()
            ->map(fn (Vehicule $v) => $this->mapVehiculeOption($v));
    }

    private function mapVehiculeOption(Vehicule $v): array
    {
        return [
            'id' => $v->id,
            'nom_vehicule' => $v->nom_vehicule,
            'immatriculation' => $v->immatriculation,
            'type_vehicule_nom' => $v->typeVehicule?->nom,
            // Plafonds par catégorie de produit (Sachet eau, Bouteille, ...), propres à ce
            // véhicule — aucun héritage depuis le type, même calcul que le contrôle serveur
            // (VehiculeCapaciteService::capacitesParCategorie), pour que le frontend affiche
            // exactement ce que le backend va vérifier. Vide = véhicule non limité.
            'capacites' => $this->vehiculeCapaciteService->capacitesParCategorieAvecNoms($v),
            'livreur_nom' => $v->equipe?->livreurs->first()?->libelleAffichage(),
            'livreur_telephone' => $v->equipe?->membres
                ->firstWhere('role', 'chauffeur')
                ?->livreur?->telephone,
            'equipe_membres' => $v->equipe?->membres
                ->map(fn ($membre) => [
                    'id' => $membre->id,
                    'nom' => $membre->livreur?->libelleAffichage() ?? 'Livreur',
                    'telephone' => $membre->livreur?->telephone,
                    'role' => $membre->role,
                ])
                ->values() ?? [],
        ];
    }

    private function clientsActifs(string $orgId): Collection
    {
        return Client::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom_complet')
            ->get()
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'nom_complet' => $c->nom_complet,
                'telephone' => $c->telephone,
                'type' => $c->type->value,
                // Véhicules externes mémorisés — facultatifs, jamais un prérequis pour vendre
                // à ce client (cf. ClientVehicle).
                'vehicules' => $c->type === ClientType::EXTERNE
                    ? $c->vehicules()->get()->map(fn ($cv) => [
                        'id' => $cv->id,
                        'libelle_affiche' => $cv->libelle_affiche,
                    ])->values()
                    : [],
            ]);
    }
}
