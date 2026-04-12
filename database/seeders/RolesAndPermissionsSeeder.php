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
        'vehicules', 'equipes-livraison', 'sites', 'produits', 'packings', 'ventes', 'achats', 'users', 'parametres',
        'logistique',
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

        // Permissions standalone (hors matrice CRUD standard)
        Permission::firstOrCreate(['name' => 'logistique.commission.verser']);

        // ── 2. Rôles + matrices de permissions ────────────────────────────────
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);
        $adminEntreprise = Role::firstOrCreate(['name' => 'admin_entreprise']);
        $manager = Role::firstOrCreate(['name' => 'manager']);
        $commerciale = Role::firstOrCreate(['name' => 'commerciale']);
        $comptable = Role::firstOrCreate(['name' => 'comptable']);
        Role::firstOrCreate(['name' => 'client']);

        $superAdmin->syncPermissions(Permission::all());

        $adminEntreprise->syncPermissions([
            'clients.create',           'clients.read',           'clients.update',           'clients.delete',
            'prestataires.create',      'prestataires.read',      'prestataires.update',      'prestataires.delete',
            'livreurs.create',          'livreurs.read',          'livreurs.update',          'livreurs.delete',
            'proprietaires.create',     'proprietaires.read',     'proprietaires.update',     'proprietaires.delete',
            'vehicules.create',         'vehicules.read',         'vehicules.update',         'vehicules.delete',
            'equipes-livraison.create', 'equipes-livraison.read', 'equipes-livraison.update', 'equipes-livraison.delete',
            'sites.create',             'sites.read',             'sites.update',             'sites.delete',
            'produits.create',          'produits.read',          'produits.update',          'produits.delete',
            'packings.create',          'packings.read',          'packings.update',          'packings.delete',
            'ventes.create',            'ventes.read',            'ventes.update',            'ventes.delete',
            'achats.create',            'achats.read',            'achats.update',            'achats.delete',
            'users.create',             'users.read',             'users.update',
            'parametres.read',          'parametres.update',
            'logistique.create',        'logistique.read',        'logistique.update',      'logistique.delete',
            'logistique.commission.verser',
        ]);

        $manager->syncPermissions([
            'clients.create',           'clients.read',           'clients.update',
            'prestataires.create',      'prestataires.read',      'prestataires.update',
            'livreurs.create',          'livreurs.read',          'livreurs.update',
            'proprietaires.read',
            'vehicules.create',         'vehicules.read',         'vehicules.update',
            'equipes-livraison.create', 'equipes-livraison.read', 'equipes-livraison.update',
            'sites.create',             'sites.read',             'sites.update',
            'produits.read',            'produits.create',        'produits.update',
            'packings.read',            'packings.create',        'packings.update',
            'ventes.create',            'ventes.read',            'ventes.update',
            'achats.create',            'achats.read',            'achats.update',
            'users.read',
            'parametres.read',
            'logistique.create',        'logistique.read',        'logistique.update',
            'logistique.commission.verser',
        ]);

        $commerciale->syncPermissions([
            'clients.create',      'clients.read',           'clients.update',
            'prestataires.create', 'prestataires.read',      'prestataires.update',
            'livreurs.create',     'livreurs.read',          'livreurs.update',
            'proprietaires.read',
            'vehicules.create',    'vehicules.read',         'vehicules.update',
            'equipes-livraison.read',
            'sites.read',
            'produits.read',
            'packings.read',
            'ventes.read',         'ventes.create',
        ]);

        $comptable->syncPermissions([
            'clients.read',           'prestataires.read',      'livreurs.read',
            'proprietaires.read',     'vehicules.read',         'equipes-livraison.read',
            'sites.read',             'produits.read',          'packings.read',
            'ventes.read',            'logistique.read',
            'logistique.commission.verser',
        ]);

        // ── 3. Organisation par défaut ────────────────────────────────────────
        $org = Organization::firstOrCreate(
            ['slug' => 'elm'],
            ['name' => 'Eau la maman', 'is_active' => true]
        );

        // ── 4. Comptes staff ──────────────────────────────────────────────────
        $pays = [
            'FR' => ['France',  '+33'],
            'GN' => ['Guinée',  '+224'],
        ];

        $staff = [
            [
                'prenom' => 'Issa',
                'nom' => 'BARRY',
                'telephone' => '+33758855039',
                'code_pays' => 'FR',
                'role' => 'super_admin',
            ],
            [
                'prenom' => 'Abdoulaye',
                'nom' => 'DIALLO',
                'telephone' => '+33769442565',
                'code_pays' => 'FR',
                'role' => 'admin_entreprise',
            ],
            [
                'prenom' => 'Moussa',
                'nom' => 'SIDIBÉ',
                'telephone' => '+224656555520',
                'code_pays' => 'GN',
                'role' => 'admin_entreprise',
            ],
            [
                'prenom' => 'Thierno Oumar',
                'nom' => 'DIALLO',
                'telephone' => '+224622176056',
                'code_pays' => 'GN',
                'role' => 'manager',
            ],
            [
                'prenom' => 'Aminata',
                'nom' => 'DIALLO',
                'telephone' => null,
                'code_pays' => null,
                'role' => 'comptable',
            ],
            [
                'prenom' => 'Alpha Oumar',
                'nom' => 'CAMARA',
                'telephone' => null,
                'code_pays' => null,
                'role' => 'commerciale',
            ],
            [
                'prenom' => 'Elhadj Oumar',
                'nom' => 'TALL',
                'telephone' => '+33605751596',
                'code_pays' => 'FR',
                'role' => 'super_admin',
            ],
            [
                'prenom' => 'Amadou',
                'nom' => 'DIALLO',
                'telephone' => '+33754158797',
                'code_pays' => 'FR',
                'role' => 'admin_entreprise',
            ],
        ];

        foreach ($staff as $data) {
            $codePays = $data['code_pays'];
            $paysNom = $codePays ? $pays[$codePays][0] : null;
            $codePhone = $codePays ? $pays[$codePays][1] : null;

            $lookup = $data['telephone']
                ? ['telephone' => $data['telephone']]
                : ['prenom' => $data['prenom'], 'nom' => $data['nom']];

            // updateOrCreate garantit que le mot de passe est toujours réinitialisé
            // lors d'un re-seed, même si le compte existe déjà.
            $user = User::updateOrCreate($lookup, [
                'prenom' => $data['prenom'],
                'nom' => $data['nom'],
                'telephone' => $data['telephone'],
                'code_pays' => $codePays,
                'pays' => $paysNom,
                'code_phone_pays' => $codePhone,
                'email' => null,
                'password' => Hash::make(self::PASSWORD),
                'organization_id' => $org->id,
            ]);

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
                ['Thierno Oumar DIALLO', '+224622176056', 'manager',          self::PASSWORD],
                ['Aminata DIALLO',      '— (à définir)',  'comptable',        self::PASSWORD],
                ['Alpha Oumar CAMARA',  '— (à définir)',  'commerciale',      self::PASSWORD],
                ['Elhadj Oumar TALL',   '+33605751596',   'super_admin',      self::PASSWORD],
                ['Amadou DIALLO',       '+33754158797',   'admin_entreprise', self::PASSWORD],
            ]
        );
        $this->command->newLine();
        $this->command->table(
            ['Rôle', 'Permissions'],
            Role::with('permissions')->get()->map(fn ($r) => [
                $r->name,
                $r->name === 'super_admin' ? 'Toutes ('.$r->permissions->count().')' : $r->permissions->count(),
            ])->toArray()
        );
    }
}
