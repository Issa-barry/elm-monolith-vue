<?php

namespace App\Http\Middleware;

use App\Models\Produit;
use App\Services\ModuleService;
use App\Support\AppVersion;
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
    private function stockAlertes(Request $request): array
    {
        $user = $request->user();
        if (! $user || ! $user->organization_id) {
            return ['ruptures' => 0, 'faibles' => 0, 'total' => 0];
        }

        $produits = Produit::where('organization_id', $user->organization_id)
            ->where('statut', '!=', 'archive')
            ->whereNotNull('qte_stock')
            ->get(['id', 'qte_stock', 'seuil_alerte_stock', 'type', 'organization_id']);

        $ruptures = $produits->filter(fn ($p) => $p->type?->hasStock() && $p->qte_stock <= 0)->count();
        $faibles = $produits->filter(fn ($p) => $p->type?->hasStock() && $p->is_low_stock)->count();

        return [
            'ruptures' => $ruptures,
            'faibles' => $faibles,
            'total' => $ruptures + $faibles,
        ];
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

    private function contactMessagesNonLus(Request $request): int
    {
        $user = $request->user();
        if (! $user || ! $user->organization_id) {
            return 0;
        }

        if (! $user->hasAnyRole(['super_admin', 'admin_entreprise', 'manager'])) {
            return 0;
        }

        return \App\Models\ContactMessage::where('organization_id', $user->organization_id)
            ->whereNull('read_at')
            ->count();
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
                'user' => $request->user()?->loadMissing('organization'),
                'permissions' => $request->user()?->permissionsMap() ?? [],
                'roles' => $request->user()?->getRoleNames() ?? [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'stock_alertes' => $this->stockAlertes($request),
            'contact_messages_non_lus' => $this->contactMessagesNonLus($request),
            'module_flags' => $this->moduleFlags($request),
            'flash' => ['success' => $request->session()->get('success')],
        ];
    }
}
