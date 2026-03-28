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
        'clients', 'prestataires', 'livreurs', 'proprietaires',
        'vehicules', 'sites', 'produits', 'packings', 'ventes', 'users', 'parametres',
    ];

    private const ACTIONS = ['create', 'read', 'update', 'delete'];

    private const PASSWORD = 'Staff@2025';

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ── 1. Permissions ────────────────────────────────────────────────────
        foreach (self::RESOURCES as $resource) {
            foreach (self::ACTIONS as $action) {
                Permission::firstOrCreate(['name' => "{$resource}.{$action}"]);
            }
        }

        // ── 2. Rôles + matrices de permissions ────────────────────────────────
        $superAdmin      = Role::firstOrCreate(['name' => 'super_admin']);
        $adminEntreprise = Role::firstOrCreate(['name' => 'admin_entreprise']);
        $manager         = Role::firstOrCreate(['name' => 'manager']);
        $commerciale     = Role::firstOrCreate(['name' => 'commerciale']);
        $comptable       = Role::firstOrCreate(['name' => 'comptable']);
        Role::firstOrCreate(['name' => 'client']);

        $superAdmin->syncPermissions(Permission::all());

        $adminEntreprise->syncPermissions([
            'clients.create',      'clients.read',      'clients.update',      'clients.delete',
            'prestataires.create', 'prestataires.read', 'prestataires.update', 'prestataires.delete',
            'livreurs.create',     'livreurs.read',     'livreurs.update',     'livreurs.delete',
            'proprietaires.create','proprietaires.read','proprietaires.update','proprietaires.delete',
            'vehicules.create',    'vehicules.read',    'vehicules.update',    'vehicules.delete',
            'sites.create',        'sites.read',        'sites.update',        'sites.delete',
            'produits.create',     'produits.read',     'produits.update',     'produits.delete',
            'packings.create',     'packings.read',     'packings.update',     'packings.delete',
            'ventes.create',       'ventes.read',       'ventes.update',       'ventes.delete',
            'users.create',        'users.read',        'users.update',
            'parametres.read',     'parametres.update',
        ]);

        $manager->syncPermissions([
            'clients.create',      'clients.read',      'clients.update',
            'prestataires.create', 'prestataires.read', 'prestataires.update',
            'livreurs.create',     'livreurs.read',     'livreurs.update',
            'proprietaires.read',
            'vehicules.create',    'vehicules.read',    'vehicules.update',
            'sites.create',        'sites.read',        'sites.update',
            'produits.read',       'produits.create',   'produits.update',
            'packings.read',       'packings.create',   'packings.update',
            'ventes.create',       'ventes.read',       'ventes.update',
            'users.read',
            'parametres.read',
        ]);

        $commerciale->syncPermissions([
            'clients.create',      'clients.read',      'clients.update',
            'prestataires.create', 'prestataires.read', 'prestataires.update',
            'livreurs.create',     'livreurs.read',     'livreurs.update',
            'proprietaires.read',
            'vehicules.create',    'vehicules.read',    'vehicules.update',
            'sites.read',
            'produits.read',
            'packings.read',
            'ventes.read',         'ventes.create',
        ]);

        $comptable->syncPermissions([
            'clients.read',        'prestataires.read', 'livreurs.read',
            'proprietaires.read',  'vehicules.read',    'sites.read',
            'produits.read',       'packings.read',     'ventes.read',
        ]);

        // ── 3. Organisation par défaut ────────────────────────────────────────
        $org = Organization::firstOrCreate(
            ['slug' => 'elm'],
            ['name' => 'Eau la maman', 'is_active' => true]
        );

        // ── 4. Comptes staff ──────────────────────────────────────────────────
        $staff = [
            [
                'prenom'    => 'Issa',
                'nom'       => 'BARRY',
                'telephone' => '+33758855039',
                'role'      => 'super_admin',
            ],
            [
                'prenom'    => 'Abdoulaye',
                'nom'       => 'DIALLO',
                'telephone' => '+33769442565',
                'role'      => 'admin_entreprise',
            ],
            [
                'prenom'    => 'Moussa',
                'nom'       => 'SIDIBÉ',
                'telephone' => '+224656555520',
                'role'      => 'admin_entreprise',
            ],
            [
                'prenom'    => 'Thierno Oumar',
                'nom'       => 'DIALLO',
                'telephone' => '+224622176056',
                'role'      => 'manager',
            ],
            [
                'prenom'    => 'Aminata',
                'nom'       => 'DIALLO',
                'telephone' => null,
                'role'      => 'comptable',
            ],
            [
                'prenom'    => 'Alpha Oumar',
                'nom'       => 'CAMARA',
                'telephone' => null,
                'role'      => 'commerciale',
            ],
        ];

        foreach ($staff as $data) {
            $lookup = $data['telephone']
                ? ['telephone' => $data['telephone']]
                : ['prenom' => $data['prenom'], 'nom' => $data['nom']];

            $user = User::firstOrCreate($lookup, [
                'prenom'          => $data['prenom'],
                'nom'             => $data['nom'],
                'telephone'       => $data['telephone'],
                'email'           => null,
                'password'        => Hash::make(self::PASSWORD),
                'organization_id' => $org->id,
            ]);

            if ($user->organization_id === null) {
                $user->update(['organization_id' => $org->id]);
            }

            $user->syncRoles([$data['role']]);
        }

        // ── 5. Résumé console ─────────────────────────────────────────────────
        $this->command->newLine();
        $this->command->info('✓ Rôles, permissions et comptes créés avec succès.');
        $this->command->newLine();
        $this->command->table(
            ['Prénom Nom', 'Téléphone', 'Rôle', 'Mot de passe'],
            [
                ['Issa BARRY',          '+33758855039',  'super_admin',      self::PASSWORD],
                ['Abdoulaye DIALLO',    '+33769442565',  'admin_entreprise', self::PASSWORD],
                ['Moussa SIDIBÉ',       '+224656555520', 'admin_entreprise', self::PASSWORD],
                ['Thierno Oumar DIALLO','+224622176056', 'manager',          self::PASSWORD],
                ['Aminata DIALLO',      '— (à définir)', 'comptable',        self::PASSWORD],
                ['Alpha Oumar CAMARA',  '— (à définir)', 'commerciale',      self::PASSWORD],
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
