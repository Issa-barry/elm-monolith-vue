<?php

namespace App\Http\Controllers\Settings\Ventes;

use App\Enums\DeclencheurCommissionVente;
use App\Http\Controllers\Controller;
use App\Models\Parametre;
use App\Support\Permissions\RoleVisibility;
use App\Support\Permissions\VenteParametragePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UpdateVenteParametrageController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = auth()->user();
        abort_if(! $user->can('parametres.update'), 403);

        VenteParametragePermissions::ensureExist();

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
        // système (organization_id null) reste visible dans la liste (cf. EditVenteParametrageController)
        // mais son état de case à cocher ne doit jamais être mutable depuis l'écran de paramétrage
        // d'une organisation — sinon toggle une organisation A modifierait silencieusement le
        // rôle partagé de l'organisation B (cf. audit § fuite cross-tenant en écriture).
        $roles = RoleVisibility::query($orgId)
            ->whereNotIn('name', ['super_admin'])
            ->get()
            ->filter(fn (Role $role) => RoleVisibility::isWritableBy($role, $orgId, $user->isSuperAdmin()));

        foreach ($roles as $role) {
            if (in_array($role->name, $enabledQuantityRoleNames, true)) {
                $role->givePermissionTo(VenteParametragePermissions::QUANTITY_UPDATE_PERMISSION);
            } else {
                $role->revokePermissionTo(VenteParametragePermissions::QUANTITY_UPDATE_PERMISSION);
            }

            if (in_array($role->name, $enabledPriceRoleNames, true)) {
                $role->givePermissionTo(VenteParametragePermissions::UNIT_PRICE_UPDATE_PERMISSION);
            } else {
                $role->revokePermissionTo(VenteParametragePermissions::UNIT_PRICE_UPDATE_PERMISSION);
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
}
