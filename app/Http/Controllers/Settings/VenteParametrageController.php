<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Parametre;
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

        $orgId = auth()->user()->organization_id;
        $this->ensureSalesPermissionsExist();

        $roles = Role::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'label' => $this->roleLabel($role->name),
                'can_update_quantite' => $role->name === 'super_admin'
                    ? true
                    : $role->hasPermissionTo(self::QUANTITY_UPDATE_PERMISSION),
                'can_update_prix_unitaire' => $role->name === 'super_admin'
                    ? true
                    : $role->hasPermissionTo(self::UNIT_PRICE_UPDATE_PERMISSION),
                'locked' => $role->name === 'super_admin',
            ])
            ->values();

        return Inertia::render('settings/Ventes', [
            'roles' => $roles,
            'commission_generation_mode' => Parametre::getVentesCommissionMode($orgId),
            'commission_options' => $this->commissionOptions(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_if(! auth()->user()->can('parametres.update'), 403);

        $this->ensureSalesPermissionsExist();

        $validated = $request->validate([
            'commission_generation_mode' => ['required', Rule::in(Parametre::ventesCommissionModes())],
            'quantity_edit_role_names' => ['array'],
            'quantity_edit_role_names.*' => ['string', Rule::exists('roles', 'name')],
            'price_edit_role_names' => ['array'],
            'price_edit_role_names.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        $enabledQuantityRoleNames = collect($validated['quantity_edit_role_names'] ?? [])
            ->values()
            ->all();
        $enabledPriceRoleNames = collect($validated['price_edit_role_names'] ?? [])
            ->values()
            ->all();

        $roles = Role::query()->whereNotIn('name', ['super_admin'])->get();

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

        Parametre::setVentesCommissionMode(
            auth()->user()->organization_id,
            $validated['commission_generation_mode'],
        );

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Parametre::clearCache(auth()->user()->organization_id);

        return back()->with('success', 'Parametrage ventes mis a jour.');
    }

    private function roleLabel(string $roleName): string
    {
        return match ($roleName) {
            'super_admin' => 'Super admin',
            'admin_entreprise' => 'Admin entreprise',
            'manager' => 'Manager',
            'commerciale' => 'Commercial',
            'comptable' => 'Comptable',
            'client' => 'Client',
            default => ucfirst(str_replace('_', ' ', $roleName)),
        };
    }

    private function commissionOptions(): array
    {
        return [
            [
                'value' => Parametre::COMMISSION_MODE_COMMANDE_VALIDEE,
                'label' => 'A la validation de commande',
                'description' => 'Les commissions sont generees des que la commande passe en cours.',
            ],
            [
                'value' => Parametre::COMMISSION_MODE_FACTURE_PAYEE,
                'label' => 'Apres encaissement complet',
                'description' => 'Les commissions sont generees uniquement quand la facture est totalement payee.',
            ],
        ];
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
