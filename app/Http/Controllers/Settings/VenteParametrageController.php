<?php

namespace App\Http\Controllers\Settings;

use App\Enums\DeclencheurCommissionVente;
use App\Http\Controllers\Controller;
use App\Models\Parametre;
use App\Support\Permissions\RoleVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class VenteParametrageController extends Controller
{
    private const QUANTITY_UPDATE_PERMISSION = 'ventes.qte.update';

    private const UNIT_PRICE_UPDATE_PERMISSION = 'ventes.prix.update';

    public function edit(): Response
    {
        abort_if(! auth()->user()->can('parametres.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $this->ensureSalesPermissionsExist();

        // Scopé à l'organisation courante (rôles système partagés ∪ rôles propres à cette
        // organisation) — un `Role::query()->get()` sans ce filtre exposait les rôles
        // personnalisés de TOUTES les organisations de la plateforme (cf. audit § sécurité).
        $roles = RoleVisibility::query($orgId)
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'label' => $role->label ?? $role->name,
                'can_update_quantite' => $role->name === 'super_admin'
                    ? true
                    : $role->hasPermissionTo(self::QUANTITY_UPDATE_PERMISSION),
                'can_update_prix_unitaire' => $role->name === 'super_admin'
                    ? true
                    : $role->hasPermissionTo(self::UNIT_PRICE_UPDATE_PERMISSION),
                // Rôle système (partagé par toutes les organisations) non modifiable par un
                // acteur qui n'est pas lui-même super_admin — cf. RoleVisibility::isWritableBy().
                'locked' => ! RoleVisibility::isWritableBy($role, $orgId, $user->isSuperAdmin()),
            ])
            ->values();

        return Inertia::render('settings/Ventes', [
            'roles' => $roles,
            'autoriser_saisie_dessous_qte_max' => Parametre::isVentesAutorisationSaisieDessousQteMax($orgId),
            'controle_impayes_actif' => Parametre::isVentesControleImpayesActif($orgId),
            'seuil_impayes_max' => Parametre::getVentesSeuilImpayesMax($orgId),
            'declencheur_commission_vente' => Parametre::getDeclencheurCommissionVente($orgId)->value,
            'declencheurs_commission_vente_options' => DeclencheurCommissionVente::options(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = auth()->user();
        abort_if(! $user->can('parametres.update'), 403);

        $this->ensureSalesPermissionsExist();

        $validated = $request->validate([
            'quantity_edit_role_names' => ['array'],
            'quantity_edit_role_names.*' => ['string', Rule::exists('roles', 'name')],
            'price_edit_role_names' => ['array'],
            'price_edit_role_names.*' => ['string', Rule::exists('roles', 'name')],
            'autoriser_saisie_dessous_qte_max' => ['required', 'boolean'],
            'controle_impayes_actif' => ['required', 'boolean'],
            'seuil_impayes_max' => ['required', 'integer', 'min:0'],
            'declencheur_commission_vente' => ['required', Rule::in(array_column(DeclencheurCommissionVente::cases(), 'value'))],
        ]);

        $enabledQuantityRoleNames = collect($validated['quantity_edit_role_names'] ?? [])
            ->values()
            ->all();
        $enabledPriceRoleNames = collect($validated['price_edit_role_names'] ?? [])
            ->values()
            ->all();

        $orgId = $user->organization_id;

        // Scopé + restreint à ce qui est réellement modifiable par cet acteur : un rôle
        // système (organization_id null) reste visible dans la liste (cf. edit()) mais son
        // état de case à cocher ne doit jamais être mutable depuis l'écran de paramétrage
        // d'une organisation — sinon toggle une organisation A modifierait silencieusement le
        // rôle partagé de l'organisation B (cf. audit § fuite cross-tenant en écriture).
        $roles = RoleVisibility::query($orgId)
            ->whereNotIn('name', ['super_admin'])
            ->get()
            ->filter(fn (Role $role) => RoleVisibility::isWritableBy($role, $orgId, $user->isSuperAdmin()));

        foreach ($roles as $role) {
            if (in_array($role->name, $enabledQuantityRoleNames, true)) {
                $role->givePermissionTo(self::QUANTITY_UPDATE_PERMISSION);
            } else {
                $role->revokePermissionTo(self::QUANTITY_UPDATE_PERMISSION);
            }

            if (in_array($role->name, $enabledPriceRoleNames, true)) {
                $role->givePermissionTo(self::UNIT_PRICE_UPDATE_PERMISSION);
            } else {
                $role->revokePermissionTo(self::UNIT_PRICE_UPDATE_PERMISSION);
            }
        }

        Parametre::setVentesAutorisationSaisieDessousQteMax($orgId, (bool) $validated['autoriser_saisie_dessous_qte_max']);

        Parametre::setVentesControleImpayes(
            $orgId,
            (bool) $validated['controle_impayes_actif'],
            (int) $validated['seuil_impayes_max'],
        );

        Parametre::setDeclencheurCommissionVente(
            $orgId,
            DeclencheurCommissionVente::from($validated['declencheur_commission_vente']),
        );

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Parametre::clearCache($orgId);

        return back()->with('success', 'Parametrage ventes mis a jour.');
    }

    private function ensureSalesPermissionsExist(): void
    {
        $quantityPermission = Permission::findOrCreate(self::QUANTITY_UPDATE_PERMISSION);
        $unitPricePermission = Permission::findOrCreate(self::UNIT_PRICE_UPDATE_PERMISSION);

        if (! $quantityPermission->wasRecentlyCreated && ! $unitPricePermission->wasRecentlyCreated) {
            return;
        }

        $defaultRoles = Role::query()
            ->whereIn('name', ['admin_entreprise', 'manager'])
            ->get();

        if ($quantityPermission->wasRecentlyCreated) {
            $defaultRoles->each(fn (Role $role) => $role->givePermissionTo(self::QUANTITY_UPDATE_PERMISSION));
        }

        if ($unitPricePermission->wasRecentlyCreated) {
            $defaultRoles->each(fn (Role $role) => $role->givePermissionTo(self::UNIT_PRICE_UPDATE_PERMISSION));
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
