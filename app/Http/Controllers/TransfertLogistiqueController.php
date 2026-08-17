<?php

namespace App\Http\Controllers;

use App\Enums\BaseCalculLogistique;
use App\Enums\StatutTransfert;
use App\Enums\TypeEcartLogistique;
use App\Jobs\NotifierLivreursTransfertJob;
use App\Models\CommissionLogistique;
use App\Models\EquipeLivraison;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use App\Services\TransfertActiviteService;
use App\Services\VehiculeCapaciteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransfertLogistiqueController extends Controller
{
    private const DATE_DISPLAY_FORMAT = 'd/m/Y';

    public function __construct(private readonly VehiculeCapaciteService $vehiculeCapaciteService) {}

    // ── Index (rétro-compatibilité — redirect géré dans routes/web.php) ──────

    // ── Index Transferts ──────────────────────────────────────────────────────

    public function indexTransferts(Request $request): Response
    {
        $this->authorize('viewAny', TransfertLogistique::class);

        return $this->buildIndex($request, 'transferts');
    }

    // ── Index Réceptions ──────────────────────────────────────────────────────

    public function indexReceptions(Request $request): Response
    {
        $this->authorize('viewAny', TransfertLogistique::class);

        return $this->buildIndex($request, 'receptions');
    }

    // ── Logique partagée des deux index ──────────────────────────────────────

    private function buildIndex(Request $request, string $vue): Response
    {
        $user = auth()->user();
        $orgId = $user->organization_id;
        $statut = $request->input('statut');
        $search = $request->input('search');
        $departSiteIds = array_values(array_filter((array) $request->input('depart_site_ids', [])));
        $arriveeSiteIds = array_values(array_filter((array) $request->input('arrivee_site_ids', [])));
        $isAdmin = $user->hasAnyRole(['super_admin', 'admin_entreprise']);
        $siteIds = $isAdmin ? collect() : $user->sites()->pluck('sites.id');
        $sites = Site::where('organization_id', $orgId)
            ->select('id', 'nom')
            ->orderBy('nom')
            ->get();
        $orgSiteIds = $sites->pluck('id');

        // Éliminer les IDs hors périmètre organisation
        $departSiteIds = collect($departSiteIds)->filter(fn ($id) => $orgSiteIds->contains($id))->values()->all();
        $arriveeSiteIds = collect($arriveeSiteIds)->filter(fn ($id) => $orgSiteIds->contains($id))->values()->all();

        // ── Requête principale ─────────────────────────────────────────────────
        $query = TransfertLogistique::with([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,vehicule_id',
            'equipeLivraison.vehicule:id,nom_vehicule',
            'commission:id,transfert_logistique_id,statut,montant_total,montant_verse',
            'lignes:id,transfert_logistique_id,variante_id,quantite_chargee,quantite_recue,ecart_type,ecart_motif',
            'lignes.variante:id,produit_id,sku',
            // image_url est un accesseur (dérivé de produit_medias, pas une colonne) : on charge
            // la relation medias plutôt que de la lister dans un select() limité aux colonnes.
            'lignes.variante.produit:id,nom',
            'lignes.variante.produit.medias',
        ])->where('organization_id', $orgId);

        if ($vue === 'receptions') {
            $statutsVue = [StatutTransfert::TRANSIT->value, StatutTransfert::RECEPTION->value, StatutTransfert::CLOTURE->value];
            $query->when($statut, fn ($q) => $q->where('statut', $statut))
                ->when(! $statut, fn ($q) => $q->whereIn('statut', $statutsVue));

            if (! $isAdmin) {
                // Non-admin : destination verrouillée sur ses sites, peut filtrer le départ
                $query->whereIn('site_destination_id', $siteIds);
                if (! empty($departSiteIds)) {
                    $query->whereIn('site_source_id', $departSiteIds);
                }
            } else {
                if (! empty($departSiteIds)) {
                    $query->whereIn('site_source_id', $departSiteIds);
                }
                if (! empty($arriveeSiteIds)) {
                    $query->whereIn('site_destination_id', $arriveeSiteIds);
                }
            }
        } else {
            // Vue Transferts
            $statutsVue = [
                StatutTransfert::BROUILLON->value,
                StatutTransfert::CHARGEMENT->value,
                StatutTransfert::TRANSIT->value,
                StatutTransfert::RECEPTION->value,
                StatutTransfert::CLOTURE->value,
                StatutTransfert::ANNULE->value,
            ];
            $query->when($statut, fn ($q) => $q->where('statut', $statut))
                ->when(! $statut, fn ($q) => $q->whereIn('statut', $statutsVue));

            if (! $isAdmin) {
                // Non-admin : départ verrouillé sur ses sites, peut filtrer l'arrivée
                $query->whereIn('site_source_id', $siteIds);
                if (! empty($arriveeSiteIds)) {
                    $query->whereIn('site_destination_id', $arriveeSiteIds);
                }
            } else {
                if (! empty($departSiteIds)) {
                    $query->whereIn('site_source_id', $departSiteIds);
                }
                if (! empty($arriveeSiteIds)) {
                    $query->whereIn('site_destination_id', $arriveeSiteIds);
                }
            }
        }

        $query->when($search, fn ($q) => $q->where('reference', 'like', "%{$search}%"))
            ->orderByDesc('created_at');

        $transferts = $query->get();

        // ── Dropdown statuts ───────────────────────────────────────────────────
        $statutsFiltre = array_values(array_filter(
            StatutTransfert::options(),
            fn ($o) => in_array($o['value'], $statutsVue, true)
        ));

        // Dans la vue réceptions, TRANSIT s'affiche "À réceptionner" (perspective destinataire)
        if ($vue === 'receptions') {
            $statutsFiltre = array_map(function ($o) {
                if ($o['value'] === StatutTransfert::TRANSIT->value) {
                    $o['label'] = 'À réceptionner';
                }

                return $o;
            }, $statutsFiltre);
        }

        // ── KPIs ──────────────────────────────────────────────────────────────
        if ($vue === 'receptions') {
            $clotureQuery = TransfertLogistique::where('organization_id', $orgId)
                ->where('statut', StatutTransfert::CLOTURE->value)
                ->whereYear('updated_at', now()->year)
                ->whereMonth('updated_at', now()->month);
            if (! $isAdmin && $siteIds->isNotEmpty()) {
                $clotureQuery->whereIn('site_destination_id', $siteIds);
            }
            $kpis = [
                'en_attente' => $transferts->filter(
                    fn ($t) => $t->statut === StatutTransfert::TRANSIT
                        || $t->statut === StatutTransfert::RECEPTION
                )->count(),
                'clotures_mois' => $clotureQuery->count(),
            ];
        } else {
            $kpis = [
                'brouillons' => $transferts->where('statut', StatutTransfert::BROUILLON)->count(),
                'en_chargement' => $transferts->where('statut', StatutTransfert::CHARGEMENT)->count(),
                'en_transit' => $transferts->where('statut', StatutTransfert::TRANSIT)->count(),
            ];
        }

        return Inertia::render('Logistique/Index', [
            'transferts' => $transferts->map(fn ($t) => $this->mapTransfert($t))->values(),
            'kpis' => $kpis,
            'statuts' => $statutsFiltre,
            'sites' => $sites->map(fn ($site) => ['id' => $site->id, 'nom' => $site->nom])->values(),
            'filtre_statut' => $statut,
            'filtre_depart_site_ids' => $departSiteIds,
            'filtre_arrivee_site_ids' => $arriveeSiteIds,
            'vue' => $vue,
            'is_admin' => $isAdmin,
            'user_site_ids' => $isAdmin ? [] : $siteIds->values()->map(fn ($id) => (string) $id)->all(),
            'can_create' => auth()->user()->can('create', TransfertLogistique::class),
            'types_ecart' => TypeEcartLogistique::options(),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function create(): Response
    {
        $this->authorize('create', TransfertLogistique::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $isAdmin = $user->isAdmin();

        // Admins : site source libre (null ici, sélectionné dans le formulaire)
        // Non-admins : site par défaut de l'utilisateur (ou premier site affecté)
        if ($isAdmin) {
            $siteSource = null;
        } else {
            $siteSourceModel = $user->sites()->where('is_default', true)->select('sites.id', 'sites.nom')->first()
                ?? $user->sites()->select('sites.id', 'sites.nom')->first();
            $siteSource = $siteSourceModel ? ['id' => $siteSourceModel->id, 'nom' => $siteSourceModel->nom] : null;
        }

        return Inertia::render('Logistique/Create', [
            'site_source' => $siteSource,
            'is_admin' => $isAdmin,
            'sites' => Site::where('organization_id', $orgId)
                ->select('id', 'nom')
                ->orderBy('nom')
                ->get(),
            'vehicules' => Vehicule::where('organization_id', $orgId)
                ->where('is_active', true)
                ->livraisonLogistique()
                ->with(['equipe:id,vehicule_id', 'capacites.groupeCapacite'])
                ->select('id', 'nom_vehicule', 'immatriculation', 'type_vehicule_id')
                ->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'nom_vehicule' => $v->nom_vehicule,
                    'immatriculation' => $v->immatriculation,
                    'equipe_livraison_id' => $v->equipe?->id,
                    'equipe_nom' => $v->equipe ? $v->nom_vehicule : null,
                    // Plafonds par groupe de capacité, propres à ce véhicule — même calcul que le
                    // contrôle serveur (VehiculeCapaciteService). Vide = véhicule non limité.
                    'capacites' => $this->vehiculeCapaciteService->capacitesParGroupeAvecNoms($v),
                ]),
            'equipes' => EquipeLivraison::with('vehicule:id,nom_vehicule')
                ->where('organization_id', $orgId)
                ->where('is_active', true)
                ->select('id', 'vehicule_id')
                ->get()
                ->sortBy(fn ($e) => $e->vehicule?->nom_vehicule)
                ->values(),
            'produits' => Produit::where('organization_id', $orgId)
                ->select('id', 'nom', 'groupe_capacite_id')
                ->orderBy('nom')
                ->get(),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', TransfertLogistique::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $isAdmin = $user->isAdmin();

        $rules = [
            'site_destination_id' => ['required', 'string', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'vehicule_id' => ['required', 'string', Rule::exists('vehicules', 'id')->where('organization_id', $orgId)->where('livraison_logistique', true)],
            'equipe_livraison_id' => ['nullable', 'string', Rule::exists('equipes_livraison', 'id')->where('organization_id', $orgId)],
            'date_depart_prevue' => ['nullable', 'date'],
            'date_arrivee_prevue' => ['nullable', 'date', 'after_or_equal:date_depart_prevue'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'string', Rule::exists('produits', 'id')->where('organization_id', $orgId)],
            'lignes.*.variante_id' => ['nullable', 'string', Rule::exists('produit_variantes', 'id')->where('organization_id', $orgId)],
            'lignes.*.quantite_demandee' => ['required', 'integer', 'min:1'],
            'lignes.*.notes' => ['nullable', 'string', 'max:250'],
        ];

        // Admins : site source soumis librement depuis le formulaire
        if ($isAdmin) {
            $rules['site_source_id'] = ['required', 'string', Rule::exists('sites', 'id')->where('organization_id', $orgId)];
        }

        $data = $request->validate($rules, [
            'site_source_id.required' => 'Le site source est obligatoire.',
            'site_source_id.exists' => 'Le site source sélectionné n\'appartient pas à votre organisation.',
            'vehicule_id.required' => 'Le véhicule est obligatoire.',
            'vehicule_id.exists' => 'Seuls les véhicules autorisés pour la logistique peuvent réaliser un transfert.',
            'date_arrivee_prevue.after_or_equal' => 'La date d\'arrivée doit être postérieure ou égale à la date de départ.',
            'lignes.required' => 'Au moins une ligne produit est requise.',
            'lignes.*.produit_id.required' => 'Chaque ligne doit avoir un produit.',
            'lignes.*.quantite_demandee.min' => 'La quantité doit être supérieure à 0.',
        ]);

        if ($isAdmin) {
            // Admins : site source validé depuis le formulaire
            if ($data['site_source_id'] === $data['site_destination_id']) {
                return back()->withErrors(['site_destination_id' => 'Le site destination doit être différent du site source.']);
            }
        } else {
            // Non-admins : site source forcé depuis le site par défaut de l'utilisateur
            $siteSource = $user->sites()->where('is_default', true)->first()
                ?? $user->sites()->first();

            if (! $siteSource) {
                return back()->withErrors(['site_source_id' => 'Vous n\'êtes affecté à aucun site.']);
            }

            $data['site_source_id'] = $siteSource->id;

            if ($data['site_destination_id'] === $siteSource->id) {
                return back()->withErrors(['site_destination_id' => 'Le site destination doit être différent du site source.']);
            }
        }

        $this->ensureQuantiteMatchesVehiculeCapacity($data);

        $transfert = DB::transaction(function () use ($data, $orgId) {
            $transfert = TransfertLogistique::create([
                'organization_id' => $orgId,
                'site_source_id' => $data['site_source_id'],
                'site_destination_id' => $data['site_destination_id'],
                'vehicule_id' => $data['vehicule_id'] ?? null,
                'equipe_livraison_id' => $data['equipe_livraison_id'] ?? null,
                'date_depart_prevue' => $data['date_depart_prevue'] ?? null,
                'date_arrivee_prevue' => $data['date_arrivee_prevue'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Lignes — dédoublonner sur variante_id
            $seen = [];
            foreach ($data['lignes'] as $ligne) {
                $variante = $this->resolveVariante($ligne);
                if (isset($seen[$variante->id])) {
                    continue;
                }
                $seen[$variante->id] = true;

                $transfert->lignes()->create([
                    'variante_id' => $variante->id,
                    'quantite_demandee' => $ligne['quantite_demandee'],
                    'notes' => $ligne['notes'] ?? null,
                ]);
            }

            return $transfert;
        });

        TransfertActiviteService::log($transfert, 'creation');

        if ($transfert->equipe_livraison_id) {
            NotifierLivreursTransfertJob::dispatch($transfert->id, $transfert->reference);
        }

        return redirect()->route('logistique.show', $transfert)
            ->with('success', 'Transfert créé avec succès.');
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(TransfertLogistique $transfert_logistique): Response
    {
        $this->authorize('view', $transfert_logistique);

        $transfert_logistique->load([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'vehicule.equipe:id,vehicule_id',
            'vehicule.proprietaire:id,personne_id',
            'vehicule.proprietaire.personne',
            'equipeLivraison:id,vehicule_id',
            'equipeLivraison.vehicule:id,nom_vehicule',
            'lignes.variante:id,produit_id,sku',
            'lignes.variante.produit:id,nom',
            'lignes.variante.produit.medias',
            'commission.parts.versements.createur:id,personne_id',
            'commission.parts.versements.createur.personne',
            'createur:id,personne_id',
            'createur.personne',
            'validateur:id,personne_id',
            'validateur.personne',
            'activites.user:id,personne_id',
            'activites.user.personne',
        ]);

        // Contexte de navigation : transferts ou réceptions
        $user = auth()->user();
        $statut = $transfert_logistique->statut;
        $isAdmin = $user->hasAnyRole(['super_admin', 'admin_entreprise']);

        if ($isAdmin) {
            $contexte = in_array($statut, [StatutTransfert::RECEPTION, StatutTransfert::CLOTURE])
                ? 'receptions' : 'transferts';
        } else {
            $siteIds = $user->sites()->pluck('sites.id');
            $isDestination = $siteIds->contains($transfert_logistique->site_destination_id);
            $contexte = ($isDestination && in_array($statut, [
                StatutTransfert::TRANSIT,
                StatutTransfert::RECEPTION,
                StatutTransfert::CLOTURE,
            ])) ? 'receptions' : 'transferts';
        }

        return Inertia::render('Logistique/Show', [
            'transfert' => $this->mapTransfertDetail($transfert_logistique),
            'contexte' => $contexte,
            'statuts' => StatutTransfert::options(),
            'types_ecart' => TypeEcartLogistique::options(),
            'bases_calcul' => BaseCalculLogistique::options(),
            'can_avancer' => $user->can('avancerStatut', $transfert_logistique),
            'can_valider_reception' => $user->can('validerReception', $transfert_logistique),
            'can_annuler' => $user->can('annuler', $transfert_logistique),
            'can_update' => $user->can('update', $transfert_logistique),
            'can_generer_commission' => $user->can('genererCommission', $transfert_logistique),
            'can_verser_commission' => $user->can('verserCommission', $transfert_logistique),
            'can_valider_reception_admin' => $user->can('validerReceptionAdmin', $transfert_logistique),
            'activites' => $transfert_logistique->activites->map(fn ($a) => [
                'id' => $a->id,
                'action' => $a->action,
                'action_label' => $a->action_label,
                'user_nom' => $a->user ? trim($a->user->prenom.' '.$a->user->nom) : 'Système',
                'details' => $a->details,
                'created_at' => $a->created_at->format('d/m/Y H:i'),
            ])->values(),
        ]);
    }

    // ── Edit ──────────────────────────────────────────────────────────────────

    public function edit(TransfertLogistique $transfert_logistique): Response
    {
        $this->authorize('update', $transfert_logistique);

        $orgId = auth()->user()->organization_id;

        $transfert_logistique->load(['lignes.variante.produit:id,nom', 'siteSource:id,nom', 'siteDestination:id,nom']);

        $siteSourceModel = $transfert_logistique->siteSource;

        return Inertia::render('Logistique/Create', [
            'transfert' => $this->mapTransfertDetail($transfert_logistique),
            'site_source' => $siteSourceModel ? ['id' => $siteSourceModel->id, 'nom' => $siteSourceModel->nom] : null,
            'is_admin' => false,
            'sites' => Site::where('organization_id', $orgId)->select('id', 'nom')->orderBy('nom')->get(),
            'vehicules' => Vehicule::where('organization_id', $orgId)->where('is_active', true)
                ->livraisonLogistique()
                ->with(['equipe:id,vehicule_id', 'capacites.groupeCapacite'])
                ->select('id', 'nom_vehicule', 'immatriculation', 'type_vehicule_id')
                ->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'nom_vehicule' => $v->nom_vehicule,
                    'immatriculation' => $v->immatriculation,
                    'equipe_livraison_id' => $v->equipe?->id,
                    'equipe_nom' => $v->equipe ? $v->nom_vehicule : null,
                    'capacites' => $this->vehiculeCapaciteService->capacitesParGroupeAvecNoms($v),
                ]),
            'equipes' => EquipeLivraison::with('vehicule:id,nom_vehicule')
                ->where('organization_id', $orgId)
                ->where('is_active', true)
                ->select('id', 'vehicule_id')
                ->get()
                ->sortBy(fn ($e) => $e->vehicule?->nom_vehicule)
                ->values(),
            'produits' => Produit::where('organization_id', $orgId)->select('id', 'nom', 'groupe_capacite_id')->orderBy('nom')->get(),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, TransfertLogistique $transfert_logistique): RedirectResponse
    {
        $this->authorize('update', $transfert_logistique);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'site_destination_id' => ['required', 'string', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'vehicule_id' => ['required', 'string', Rule::exists('vehicules', 'id')->where('organization_id', $orgId)->where('livraison_logistique', true)],
            'equipe_livraison_id' => ['nullable', 'string', Rule::exists('equipes_livraison', 'id')->where('organization_id', $orgId)],
            'date_depart_prevue' => ['nullable', 'date'],
            'date_arrivee_prevue' => ['nullable', 'date', 'after_or_equal:date_depart_prevue'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'string', Rule::exists('produits', 'id')->where('organization_id', $orgId)],
            'lignes.*.variante_id' => ['nullable', 'string', Rule::exists('produit_variantes', 'id')->where('organization_id', $orgId)],
            'lignes.*.quantite_demandee' => ['required', 'integer', 'min:1'],
            'lignes.*.notes' => ['nullable', 'string', 'max:250'],
        ], [
            'vehicule_id.required' => 'Le véhicule est obligatoire.',
            'vehicule_id.exists' => 'Seuls les véhicules autorisés pour la logistique peuvent réaliser un transfert.',
            'lignes.*.produit_id.required' => 'Chaque ligne doit avoir un produit.',
            'lignes.*.quantite_demandee.min' => 'La quantité doit être supérieure à 0.',
        ]);

        // Le site source est immuable : on garde la valeur existante
        $data['site_source_id'] = $transfert_logistique->site_source_id;

        if ($data['site_destination_id'] === $data['site_source_id']) {
            return back()->withErrors(['site_destination_id' => 'Le site destination doit être différent du site source.']);
        }

        $this->ensureQuantiteMatchesVehiculeCapacity($data);

        DB::transaction(function () use ($data, $transfert_logistique) {
            $transfert_logistique->update([
                'site_source_id' => $data['site_source_id'],
                'site_destination_id' => $data['site_destination_id'],
                'vehicule_id' => $data['vehicule_id'] ?? null,
                'equipe_livraison_id' => $data['equipe_livraison_id'] ?? null,
                'date_depart_prevue' => $data['date_depart_prevue'] ?? null,
                'date_arrivee_prevue' => $data['date_arrivee_prevue'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Remplacer toutes les lignes
            $transfert_logistique->lignes()->delete();

            $seen = [];
            foreach ($data['lignes'] as $ligne) {
                $variante = $this->resolveVariante($ligne);
                if (isset($seen[$variante->id])) {
                    continue;
                }
                $seen[$variante->id] = true;

                $transfert_logistique->lignes()->create([
                    'variante_id' => $variante->id,
                    'quantite_demandee' => $ligne['quantite_demandee'],
                    'notes' => $ligne['notes'] ?? null,
                ]);
            }
        });

        return redirect()->route('logistique.show', $transfert_logistique)
            ->with('success', 'Transfert mis à jour.');
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function destroy(TransfertLogistique $transfert_logistique): RedirectResponse
    {
        $this->authorize('delete', $transfert_logistique);

        $transfert_logistique->delete();

        return redirect()->route('logistique.index')
            ->with('success', 'Transfert supprimé.');
    }

    // ── Mapping ───────────────────────────────────────────────────────────────

    private function mapTransfert(TransfertLogistique $t): array
    {
        $user = auth()->user();

        return [
            'id' => $t->id,
            'reference' => $t->reference,
            'site_source_nom' => $t->siteSource?->nom,
            'site_destination_nom' => $t->siteDestination?->nom,
            'vehicule_nom' => $t->vehicule?->nom_vehicule,
            'immatriculation' => $t->vehicule?->immatriculation,
            'equipe_nom' => $t->equipeLivraison?->nom,
            'statut' => $t->statut?->value,
            'statut_label' => $t->statut_label,
            'statut_dot_class' => $t->statut instanceof StatutTransfert ? $this->statutDotClass($t->statut) : 'bg-zinc-400',
            'date_depart_prevue' => $t->date_depart_prevue?->format(self::DATE_DISPLAY_FORMAT),
            'date_arrivee_prevue' => $t->date_arrivee_prevue?->format(self::DATE_DISPLAY_FORMAT),
            'date_depart_reelle' => $t->date_depart_reelle?->format(self::DATE_DISPLAY_FORMAT),
            'date_arrivee_reelle' => $t->date_arrivee_reelle?->format(self::DATE_DISPLAY_FORMAT),
            'commission_statut' => $t->commission?->statut?->value,
            'commission_statut_label' => $t->commission?->statut_label,
            'is_brouillon' => $t->isBrouillon(),
            'is_cloture' => $t->isCloture(),
            'is_terminal' => $t->isTerminal(),
            'is_annule' => $t->isAnnule(),
            'is_editable' => $t->isEditable(),
            'can_annuler' => $user->can('annuler', $t),
            'can_valider_reception' => $user->can('validerReception', $t),
            'created_at' => $t->created_at?->format(self::DATE_DISPLAY_FORMAT),
            'lignes_reception' => $t->statut === StatutTransfert::TRANSIT
                ? $t->lignes->map(fn ($l) => [
                    'id' => $l->id,
                    'produit_nom' => $l->variante?->produit?->nom,
                    'variante_libelle' => $l->variante?->libelle,
                    'quantite_chargee' => $l->quantite_chargee,
                    'quantite_recue' => $l->quantite_recue,
                    'ecart_type' => $l->ecart_type?->value,
                    'ecart_motif' => $l->ecart_motif,
                ])->values()->all()
                : [],
        ];
    }

    private function mapTransfertDetail(TransfertLogistique $t): array
    {
        $base = $this->mapTransfert($t);

        $base['notes'] = $t->notes;
        $base['vehicule_id'] = $t->vehicule_id;
        $base['equipe_livraison_id'] = $t->equipe_livraison_id;
        $base['site_source_id'] = $t->site_source_id;
        $base['site_destination_id'] = $t->site_destination_id;
        $base['createur'] = $t->createur ? trim($t->createur->prenom.' '.$t->createur->nom) : null;

        $base['validation_reception'] = $t->validation_reception;
        $base['validated_by_nom'] = $t->validateur ? trim($t->validateur->prenom.' '.$t->validateur->nom) : null;
        $base['validated_at'] = $t->validated_at?->format('d/m/Y H:i');
        $base['validation_motif'] = $t->validation_motif;

        $base['lignes'] = $t->lignes->map(fn ($l) => [
            'id' => $l->id,
            'variante_id' => $l->variante_id,
            'produit_id' => $l->variante?->produit_id,
            'produit_nom' => $l->variante?->produit?->nom,
            'variante_libelle' => $l->variante?->libelle,
            'sku' => $l->variante?->sku,
            'produit_image_url' => $l->variante?->produit?->image_url,
            'quantite_demandee' => $l->quantite_demandee,
            'quantite_chargee' => $l->quantite_chargee,
            'quantite_recue' => $l->quantite_recue,
            'ecart' => $l->ecart,
            'ecart_type' => $l->ecart_type?->value,
            'ecart_label' => $l->ecart_label,
            'ecart_dot_class' => $l->ecart_dot_class,
            'ecart_motif' => $l->ecart_motif,
            'notes' => $l->notes,
            'est_reception_complete' => $l->estReceptionComplete(),
        ])->values()->all();

        if ($t->commission) {
            $base['commission'] = $this->mapCommission($t->commission);
        } else {
            $base['commission'] = null;
        }

        return $base;
    }

    private function mapCommission(CommissionLogistique $c): array
    {
        return [
            'id' => $c->id,
            'base_calcul' => $c->base_calcul?->value,
            'base_calcul_label' => $c->base_calcul?->label(),
            'valeur_base' => (float) $c->valeur_base,
            'quantite_reference' => $c->quantite_reference,
            'montant_total' => (float) $c->montant_total,
            'montant_verse' => (float) $c->montant_verse,
            'montant_restant' => (float) $c->montant_restant,
            'statut' => $c->statut?->value,
            'statut_label' => $c->statut_label,
            'statut_dot_class' => $c->statut_dot_class,
            'is_versee' => $c->isVersee(),
            'parts' => $c->relationLoaded('parts') ? $c->parts->map(fn ($p) => [
                'id' => $p->id,
                'type_beneficiaire' => $p->type_beneficiaire,
                'beneficiaire_nom' => $p->beneficiaire_nom,
                'taux_commission' => (float) $p->taux_commission,
                'montant_brut' => (float) $p->montant_brut,
                'frais_supplementaires' => (float) $p->frais_supplementaires,
                'montant_net' => (float) $p->montant_net,
                'montant_a_payer' => $p->montant_a_payer,
                'montant_verse' => (float) $p->montant_verse,
                'montant_restant' => (float) $p->montant_restant,
                'statut' => $p->statut?->value,
                'statut_label' => $p->statut_label,
                'statut_dot_class' => $p->statut_dot_class,
                'is_versee' => $p->isVersee(),
                'versements' => $p->versements->map(fn ($v) => [
                    'id' => $v->id,
                    'montant' => (float) $v->montant,
                    'date_versement' => $v->date_versement?->format(self::DATE_DISPLAY_FORMAT),
                    'enregistre_le' => $v->created_at?->format('d/m/Y H:i'),
                    'mode_paiement' => $v->mode_paiement,
                    'note' => $v->note,
                    'created_by' => $v->createur ? trim($v->createur->prenom.' '.$v->createur->nom) : null,
                ])->values()->all(),
            ])->values()->all() : [],
        ];
    }

    private function statutDotClass(StatutTransfert $statut): string
    {
        return $statut->dotClass();
    }

    /**
     * Même contrôle que la vente web/PDV (VehiculeCapaciteService), mais sans exigence de
     * chargement complet : un transfert peut charger moins que la capacité du véhicule, il ne
     * peut simplement jamais la dépasser. $lignes utilise 'quantite_demandee' (pas 'qte' comme la
     * vente), seule différence avec l'appel équivalent de CommandeVenteController.
     *
     * @throws ValidationException
     */
    private function ensureQuantiteMatchesVehiculeCapacity(array $data): void
    {
        $vehicule = Vehicule::query()->find($data['vehicule_id'] ?? null);
        if (! $vehicule) {
            return;
        }

        $this->vehiculeCapaciteService->verifier($vehicule, $data['lignes'] ?? [], 'quantite_demandee', false);
    }

    /**
     * Résout la variante à transférer. Le formulaire actuel ne propose qu'un sélecteur de
     * produit (pas encore de sélecteur de variante — Phase 3) ; si le produit n'a qu'une
     * seule variante (cas normal), on la prend directement.
     */
    private function resolveVariante(array $ligne): ProduitVariante
    {
        if (! empty($ligne['variante_id'])) {
            return ProduitVariante::findOrFail($ligne['variante_id']);
        }

        $produit = Produit::with('variantes')->findOrFail($ligne['produit_id']);

        if ($produit->variantes->count() === 1) {
            return $produit->variantes->first();
        }

        if ($produit->variantes->count() > 1) {
            throw ValidationException::withMessages([
                'lignes' => "Le produit « {$produit->nom} » a plusieurs déclinaisons — précisez la variante à transférer.",
            ]);
        }

        throw ValidationException::withMessages([
            'lignes' => "Le produit « {$produit->nom} » n'a aucune variante disponible.",
        ]);
    }
}
