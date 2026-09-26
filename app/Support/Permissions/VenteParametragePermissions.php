<?php

namespace App\Support\Permissions;

use Illuminate\Support\Facades\App;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions dédiées à la délégation ventes (quantité/prix unitaire) par rôle — extrait de
 * l'ancien VenteParametrageController, partagé entre EditVenteParametrageController (affichage
 * des cases à cocher) et UpdateVenteParametrageController (application de la sélection).
 */
final class VenteParametragePermissions
{
    public const QUANTITY_UPDATE_PERMISSION = 'ventes.qte.update';

    public const UNIT_PRICE_UPDATE_PERMISSION = 'ventes.prix.update';

    public static function ensureExist(): void
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

        App::make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
