<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    private const RESOURCES = [
        'clients',
        'prestataires',
        'livreurs',
        'proprietaires',
        'produits',
        'packings',
        'users',
    ];

    private const ACTIONS = ['create', 'read', 'update', 'delete'];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ── 1. Permissions ────────────────────────────────────────────────────
        foreach (self::RESOURCES as $resource) {
            foreach (self::ACTIONS as $action) {
                Permission::firstOrCreate(['name' => "{$resource}.{$action}"]);
            }
        }

        // ── 2. Rôles + matrice ────────────────────────────────────────────────
        $superAdmin      = Role::firstOrCreate(['name' => 'super_admin']);
        $adminEntreprise = Role::firstOrCreate(['name' => 'admin_entreprise']);
        $commerciale     = Role::firstOrCreate(['name' => 'commerciale']);
        $comptable       = Role::firstOrCreate(['name' => 'comptable']);

        $superAdmin->syncPermissions(Permission::all());

        $adminEntreprise->syncPermissions([
            'clients.create', 'clients.read', 'clients.update', 'clients.delete',
            'prestataires.create', 'prestataires.read', 'prestataires.update', 'prestataires.delete',
            'livreurs.create', 'livreurs.read', 'livreurs.update', 'livreurs.delete',
            'proprietaires.create', 'proprietaires.read', 'proprietaires.update', 'proprietaires.delete',
            'produits.create', 'produits.read', 'produits.update', 'produits.delete',
            'packings.create', 'packings.read', 'packings.update', 'packings.delete',
            'users.create', 'users.read', 'users.update',
        ]);

        $commerciale->syncPermissions([
            'clients.create', 'clients.read', 'clients.update',
            'prestataires.create', 'prestataires.read', 'prestataires.update',
            'livreurs.create', 'livreurs.read', 'livreurs.update',
            'proprietaires.read',
            'produits.read',
            'packings.read',
        ]);

        $comptable->syncPermissions([
            'clients.read',
            'prestataires.read',
            'livreurs.read',
            'proprietaires.read',
            'produits.read',
            'packings.read',
        ]);

        // ── 3. Organisation par défaut ────────────────────────────────────────
        $org = Organization::firstOrCreate(
            ['slug' => 'elm-demo'],
            ['name' => 'ELM Demo']
        );

        // ── 4. Comptes principaux ─────────────────────────────────────────────
        //
        //  super_admin  →  superadmin@admin.com  /  password
        //  admin        →  admin@admin.com       /  password
        //
        $accounts = [
            [
                'email' => 'superadmin@admin.com',
                'name'  => 'Super Admin',
                'role'  => 'super_admin',
                'org'   => $org->id,
            ],
            [
                'email' => 'admin@admin.com',
                'name'  => 'Admin Entreprise',
                'role'  => 'admin_entreprise',
                'org'   => $org->id,
            ],
            [
                'email' => 'commercial@admin.com',
                'name'  => 'Commerciale',
                'role'  => 'commerciale',
                'org'   => $org->id,
            ],
            [
                'email' => 'comptable@admin.com',
                'name'  => 'Comptable',
                'role'  => 'comptable',
                'org'   => $org->id,
            ],
        ];

        foreach ($accounts as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name'            => $data['name'],
                    'password'        => Hash::make('password'),
                    'organization_id' => $data['org'],
                ]
            );

            // Met à jour l'org si l'utilisateur existait déjà sans org
            if ($user->organization_id === null && $data['org'] !== null) {
                $user->update(['organization_id' => $data['org']]);
            }

            $user->syncRoles([$data['role']]);
        }

        // ── 5. Résumé console ─────────────────────────────────────────────────
        $this->command->newLine();
        $this->command->info('✓ Rôles et permissions créés avec succès.');
        $this->command->newLine();
        $this->command->table(
            ['Email', 'Rôle', 'Mot de passe'],
            [
                ['superadmin@admin.com', 'super_admin',      'password'],
                ['admin@admin.com',      'admin_entreprise', 'password'],
                ['commercial@admin.com', 'commerciale',      'password'],
                ['comptable@admin.com',  'comptable',        'password'],
            ]
        );
        $this->command->newLine();
        $this->command->table(
            ['Rôle', 'Permissions'],
            Role::with('permissions')->get()->map(fn ($r) => [
                $r->name,
                $r->name === 'super_admin' ? 'Toutes (' . $r->permissions->count() . ')' : $r->permissions->count(),
            ])->toArray()
        );
    }
}
