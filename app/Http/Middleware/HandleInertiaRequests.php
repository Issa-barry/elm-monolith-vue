<?php

namespace App\Http\Middleware;

use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use App\Enums\StatutTransfert;
use App\Models\ContactMessage;
use App\Models\MouvementFonds;
use App\Models\PropositionVehicule;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Services\ModuleService;
use App\Services\StockStatutService;
use App\Services\ThemePolicyService;
use App\Support\AppVersion;
use App\Support\Permissions\RoleVisibility;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    /**
     * Compteur global (badge sidebar) — délègue à StockStatutService::compterAlertesPourOrganisation()
     * (source unique de la règle, une seule requête agrégée en SQL) plutôt que de réimplémenter
     * la comparaison qte/seuil ici. Ce calcul s'exécute à chaque chargement de page (middleware
     * Inertia partagé) : un N+1 par produit serait inacceptable en performance.
     */
    private function stockAlertes(Request $request): array
    {
        $user = $request->user();
        if (! $user || ! $user->organization_id) {
            return ['ruptures' => 0, 'faibles' => 0, 'total' => 0];
        }

        return app(StockStatutService::class)->compterAlertesPourOrganisation($user->organization_id);
    }

    /**
     * Thème global (preset PrimeVue / couleur principale / surface), partagé à
     * chaque page — pas seulement l'écran de réglages, cf. docs/theming.md.
     * Résolu même pour un visiteur non authentifié (page de login) en retombant
     * sur la première organisation du déploiement, comme ModuleService le fait
     * déjà pour les flags publics.
     */
    private function theme(Request $request): array
    {
        $orgId = $request->user()?->organization_id
            ?? ModuleService::publicOrganization()?->id;

        return app(ThemePolicyService::class)->sharedPayload($orgId);
    }

    private function moduleFlags(Request $request): array
    {
        $user = $request->user();
        if (! $user || ! $user->organization_id) {
            return [];
        }

        // loadMissing est idempotent : pas de requête supplémentaire si déjà chargé
        $org = $user->loadMissing('organization')->organization;
        if (! $org) {
            return [];
        }

        // Les clés sont simplifiées (sans préfixe 'module.')
        // pour éviter les problèmes de dot-notation dans les assertions Inertia/Vue
        $raw = ModuleService::allForOrg($org);
        $flags = [];
        foreach ($raw as $key => $value) {
            $flags[str_replace('module.', '', $key)] = $value;
        }

        return $flags;
    }

    /**
     * Nombre de transferts en TRANSIT destinés aux sites de l'utilisateur.
     * Affiché comme badge sur le menu Réceptions.
     */
    private function transfertsAReceptionner(Request $request): int
    {
        $user = $request->user();
        if (! $user || ! $user->organization_id) {
            return 0;
        }
        if (! $user->can('logistique.read')) {
            return 0;
        }

        $query = TransfertLogistique::where('organization_id', $user->organization_id)
            ->where('statut', StatutTransfert::TRANSIT->value);

        // Notification uniquement pour les utilisateurs lies au site de destination.
        $siteIds = $user->sites()->pluck('sites.id');
        if ($siteIds->isEmpty()) {
            return 0;
        }

        return $query->whereIn('site_destination_id', $siteIds)->count();
    }

    /**
     * Mouvements de fonds ENVOYÉS entre agences (nature `inter_sites` — un versement de caisse
     * dédiée `interne_caisses` suit son propre workflow, déjà traité par sa page dédiée) destinés
     * à un site de l'utilisateur et qu'il est habilité à confirmer. Affiché comme badge sur le
     * menu Mouvements (Comptabilité > Trésorerie). Le badge disparaît de lui-même dès la
     * confirmation (ENVOYE -> RECU) : ce compteur relit l'état réel du mouvement, il ne mémorise
     * aucun état de lecture séparé.
     *
     * Un admin (`isAdmin()`) voit le compteur org-wide, sans restriction de site — pas seulement
     * pour ses propres sites rattachés. C'est nécessaire pour rester cohérent avec le reste de la
     * fonctionnalité : `MouvementFondsPolicy::recevoir()` laisse déjà un admin confirmer un
     * mouvement même sans y être personnellement affecté, et `MouvementFondsController::
     * mouvementsVisibles()` lui montre déjà tous les mouvements de l'organisation. Un admin
     * pourrait donc déjà AGIR sur un mouvement sans jamais être PRÉVENU qu'une action l'attend —
     * incident constaté le 22/09/2026 (super admin rattaché uniquement au siège, mouvement envoyé
     * vers une autre agence, badge resté à 0). Volontairement différent de
     * transfertsAReceptionner() ci-dessus, qui n'a pas cette dérogation (non corrigé, hors
     * périmètre de ce chantier).
     */
    private function mouvementsFondsAConfirmer(Request $request): int
    {
        $user = $request->user();
        if (! $user || ! $user->organization_id) {
            return 0;
        }
        if (! $user->can('tresorerie.recevoir')) {
            return 0;
        }

        $query = MouvementFonds::where('organization_id', $user->organization_id)
            ->where('nature', NatureMouvementFonds::INTER_SITES->value)
            ->where('statut', StatutMouvementFonds::ENVOYE->value);

        if (! $user->isAdmin()) {
            $siteIds = $user->sites()->pluck('sites.id');
            if ($siteIds->isEmpty()) {
                return 0;
            }
            $query->whereIn('site_destination_id', $siteIds);
        }

        return $query->count();
    }

    private function propositionsATraiter(Request $request): int
    {
        $user = $request->user();
        if (! $user || ! $user->organization_id) {
            return 0;
        }
        if (! $user->can('propositions.read')) {
            return 0;
        }

        return PropositionVehicule::where('organization_id', $user->organization_id)
            ->whereIn('statut', ['soumise', 'en_revision', 'a_completer'])
            ->count();
    }

    private function contactMessagesNonLus(Request $request): int
    {
        $user = $request->user();
        if (! $user || ! $user->organization_id) {
            return 0;
        }

        if (! $user->hasAnyRole(['super_admin', 'admin_entreprise', 'manager'])) {
            return 0;
        }

        return ContactMessage::where('organization_id', $user->organization_id)
            ->whereNull('read_at')
            ->count();
    }

    private function orgSites(Request $request): array
    {
        $user = $request->user();
        if (! $user || ! $user->organization_id) {
            return [];
        }

        return Site::where('organization_id', $user->organization_id)
            ->orderBy('nom')
            ->get(['id', 'nom'])
            ->toArray();
    }

    private function seoDefaults(): array
    {
        return [
            'siteName' => config('seo.site_name'),
            'baseUrl' => rtrim(config('app.url'), '/'),
            'defaultImage' => config('seo.default_image'),
            'locale' => config('seo.locale'),
            'twitterSite' => config('seo.twitter_site'),
            'organization' => config('seo.organization'),
        ];
    }

    private function userSites(Request $request): array
    {
        $user = $request->user();
        if (! $user || ! $user->organization_id) {
            return [];
        }
        if ($user->isAdmin()) {
            return []; // Admin = pas de restriction de périmètre
        }

        return $user->sites()
            ->orderBy('nom')
            ->get(['sites.id', 'sites.nom'])
            ->map(fn ($s) => ['id' => (string) $s->id, 'nom' => $s->nom])
            ->toArray();
    }

    /**
     * Contrat frontend explicite pour `auth.user` (cf. resources/js/types/index.d.ts,
     * interface User) — jamais le modèle Eloquent brut. `$request->user()` porte
     * potentiellement, selon ce qui a été chargé ailleurs dans la requête (policies,
     * gates, middlewares), les relations `organization`, `personne`, `authIdentities`
     * (avec `verification_token`/`verification_expires_at`), `roles` (avec pivot),
     * `permissions` — une sérialisation directe du modèle les expose TOUTES au
     * navigateur, quelle que soit leur sensibilité (incident 2026-08-25 : payload JSON
     * affiché brut sur /backoffice/achats/{id}, révélant ces relations en clair).
     * `$hidden` sur User ne protège que ses propres colonnes, jamais les relations
     * chargées — d'où cette liste blanche explicite, seule protection fiable.
     */
    private function authUserPayload(Request $request): ?array
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $organization = $user->organization;

        return [
            'id' => $user->id,
            'prenom' => $user->prenom,
            'nom' => $user->nom,
            'name' => $user->name,
            'email' => $user->email,
            'telephone' => $user->telephone,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
            'organization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'logo_url' => $organization->logo_url,
            ] : null,
        ];
    }

    /**
     * Libellé humain de chaque rôle visible par l'organisation courante (système ∪ propres à
     * l'organisation), par nom technique. Remplace les dictionnaires ROLE_LABELS figés
     * individuellement dans RoleBadges.vue/UserInfo.vue/HeaderWidget.vue/UserForm.vue/
     * Profile.vue/ProduitParametrage.vue/DepenseParametrage.vue/Sites/Show.vue/
     * client/Dashboard.vue (2026-09-06) — un rôle personnalisé d'organisation ou un libellé
     * modifié depuis /backoffice/roles est désormais reflété partout via cette seule source,
     * jamais recopié localement.
     */
    private function roleLabels(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        return RoleVisibility::query($user->organization_id)->pluck('label', 'name')->all();
    }

    private function defaultSite(Request $request): ?array
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $site = $user->sites()
            ->wherePivot('is_default', true)
            ->select('sites.id', 'sites.nom', 'sites.type')
            ->first();

        if (! $site) {
            return null;
        }

        return [
            'id' => $site->id,
            'nom' => $site->nom,
            'type' => $site->type instanceof \BackedEnum ? $site->type->value : (string) $site->type,
            'type_label' => $site->type_label,
            'label' => $site->label,
        ];
    }

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'appVersion' => AppVersion::current(),
            'appVersionLabel' => AppVersion::label(),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $this->authUserPayload($request),
                'permissions' => $request->user()?->permissionsMap() ?? [],
                'roles' => $request->user()?->getRoleNames() ?? [],
                'role_labels' => $this->roleLabels($request),
                'default_site' => $this->defaultSite($request),
                'user_sites' => $this->userSites($request),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'stock_alertes' => $this->stockAlertes($request),
            'contact_messages_non_lus' => $this->contactMessagesNonLus($request),
            'transferts_a_receptionner' => $this->transfertsAReceptionner($request),
            'mouvements_fonds_a_confirmer' => $this->mouvementsFondsAConfirmer($request),
            'propositions_a_traiter' => $this->propositionsATraiter($request),
            'module_flags' => $this->moduleFlags($request),
            'theme' => $this->theme($request),
            'org_sites' => $this->orgSites($request),
            'seoDefaults' => $this->seoDefaults(),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'pdv_commande' => $request->session()->get('pdv_commande'),
                'created_categorie_id' => $request->session()->get('created_categorie_id'),
                'created_option_catalogue_id' => $request->session()->get('created_option_catalogue_id'),
                'created_option_catalogue_valeur_id' => $request->session()->get('created_option_catalogue_valeur_id'),
                'created_fournisseur_id' => $request->session()->get('created_fournisseur_id'),
                'created_fonction_rh_id' => $request->session()->get('created_fonction_rh_id'),
            ],
        ];
    }
}
