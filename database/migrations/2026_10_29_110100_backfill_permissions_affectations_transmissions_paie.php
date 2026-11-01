<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Migration de DONNÉES — permissions des primes des salariés (ADR 0039) et de la transmission de
 * paie (ADR 0040). Valider une affectation, valider et transmettre une version ne sont accordés
 * qu'au super administrateur ; admin_entreprise propose et prépare ; comptable consulte la
 * transmission. Délégables dans /backoffice/roles. Non destructive et idempotente.
 */
return new class extends Migration
{
    private const AFFECTATIONS = [
        'rh-affectations-remuneration.create',
        'rh-affectations-remuneration.read',
        'rh-affectations-remuneration.update',
        'rh-affectations-remuneration.delete',
    ];

    private const VALIDATION = [
        'rh-affectations-remuneration.valider',
        'rh-transmissions-paie.valider',
        'rh-transmissions-paie.transmettre',
    ];

    private const TRANSMISSION = [
        'rh-transmissions-paie.read',
        'rh-transmissions-paie.preparer',
    ];

    public function up(): void
    {
        foreach ([...self::AFFECTATIONS, ...self::VALIDATION, ...self::TRANSMISSION] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $parRole = [
            'super_admin' => [...self::AFFECTATIONS, ...self::VALIDATION, ...self::TRANSMISSION],
            'admin_entreprise' => [...self::AFFECTATIONS, ...self::TRANSMISSION],
            'comptable' => ['rh-transmissions-paie.read'],
        ];

        Role::query()
            ->whereIn('name', array_keys($parRole))
            ->with('permissions')
            ->get()
            ->each(function (Role $role) use ($parRole) {
                foreach ($parRole[$role->name] as $name) {
                    if (! $role->permissions->contains('name', $name)) {
                        $role->givePermissionTo($name);
                    }
                }
            });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /** Pas de rollback de données : ces permissions ont pu être configurées volontairement depuis. */
    public function down(): void
    {
        //
    }
};
