<?php

namespace App\Http\Controllers\Settings\Ventes;

use App\Enums\DeclencheurCommissionVente;
use App\Http\Controllers\Controller;
use App\Models\Parametre;
use App\Support\Permissions\RoleVisibility;
use App\Support\Permissions\VenteParametragePermissions;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class EditVenteParametrageController extends Controller
{
    public function __invoke(): Response
    {
        abort_if(! auth()->user()->can('parametres.read'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;
        VenteParametragePermissions::ensureExist();

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
                    : $role->hasPermissionTo(VenteParametragePermissions::QUANTITY_UPDATE_PERMISSION),
                'can_update_prix_unitaire' => $role->name === 'super_admin'
                    ? true
                    : $role->hasPermissionTo(VenteParametragePermissions::UNIT_PRICE_UPDATE_PERMISSION),
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
}
