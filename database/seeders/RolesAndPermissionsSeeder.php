<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    private const RESOURCES = [
        // Personnes
        'clients', 'prestataires', 'livreurs', 'proprietaires', 'pieces-identite',
        // Véhicules & logistique terrain
        'vehicules', 'type-vehicules', 'equipes-livraison', 'sites',
        // Commerce
        'produits', 'categories', 'options', 'type-produits', 'packings', 'ventes', 'achats', 'fournisseurs', 'factures', 'commissions', 'cashback', 'pdv',
        // Opérations
        'logistique', 'transferts', 'receptions',
        // Finances
        'depenses', 'comptabilite', 'journal-financier',
        // RH
        'rh-employes', 'rh-contrats', 'rh-paie',
        // Administration
        'users',
        // Paramètres
        'parametres', 'parametres-produits', 'parametres-depenses', 'parametres-ventes', 'parametres-systeme', 'modules-metier',
    ];

    private const ACTIONS = ['create', 'read', 'update', 'delete'];

    /**
     * Ne crée JAMAIS de compte de démo — sécurité indépendante d'APP_ENV, garantie par le seul
     * fait que ce seeder n'appelle plus ElmDemoAccountsSeeder (cf. son docblock pour le contexte).
     * Le seul compte réel de mise en prod est créé par `php artisan app:install` (cf. InstallApp),
     * jamais par un seeder. Les comptes de démo "elm", eux, sont ajoutés explicitement par
     * DatabaseSeeder (dev local / CI) via ElmDemoAccountsSeeder, jamais ici.
     */
    public function run(): void
    {
        self::seedRolesEtPermissions();

        // ── 3. Organisation par défaut ────────────────────────────────────────
        Organization::firstOrCreate(
            ['slug' => 'elm'],
            ['name' => 'Eau la maman', 'is_active' => true]
        );
    }

    /**
     * Permissions + rôles + matrices — entièrement indépendant de toute organisation, donc
     * réutilisable tel quel par `php artisan app:install` (InstallApp) pour une organisation
     * fraîchement créée, sans dupliquer/hardcoder "elm" comme le fait run() ci-dessus.
     */
    public static function seedRolesEtPermissions(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ── 1. Permissions ────────────────────────────────────────────────────
        foreach (self::RESOURCES as $resource) {
            foreach (self::ACTIONS as $action) {
                Permission::firstOrCreate(['name' => "{$resource}.{$action}"]);
            }
        }

        // Permissions standalone (hors matrice CRUD standard)
        // — Existantes —
        Permission::firstOrCreate(['name' => 'logistique.commission.verser']);
        Permission::firstOrCreate(['name' => 'ventes.qte.update']);
        Permission::firstOrCreate(['name' => 'ventes.prix.update']);
        Permission::firstOrCreate(['name' => 'rh-paie.validate']);
        Permission::firstOrCreate(['name' => 'rh-paie.pay']);
        Permission::firstOrCreate(['name' => 'rh-paie.close']);
        // — Import flotte (propriétaires + véhicules + livreurs) —
        Permission::firstOrCreate(['name' => 'imports-flotte.create']);
        Permission::firstOrCreate(['name' => 'imports-flotte.read']);
        // — Pièces d'identité (workflow de vérification — actuellement sur Proprietaire) —
        Permission::firstOrCreate(['name' => 'pieces-identite.download']);
        Permission::firstOrCreate(['name' => 'pieces-identite.valider']);
        Permission::firstOrCreate(['name' => 'pieces-identite.rejeter']);
        Permission::firstOrCreate(['name' => 'comptabilite.payer']);
        // — Dépenses (workflow) —
        Permission::firstOrCreate(['name' => 'depenses.soumettre']);
        Permission::firstOrCreate(['name' => 'depenses.valider']);
        Permission::firstOrCreate(['name' => 'depenses.rejeter']);
        Permission::firstOrCreate(['name' => 'depenses.annuler']);
        // — Produits —
        Permission::firstOrCreate(['name' => 'produits.ajuster_stock']);
        // — Ventes (workflow) —
        Permission::firstOrCreate(['name' => 'ventes.confirmer']);
        Permission::firstOrCreate(['name' => 'ventes.annuler']);
        Permission::firstOrCreate(['name' => 'ventes.demarrer_chargement']);
        Permission::firstOrCreate(['name' => 'ventes.valider_chargement']);
        // — Factures —
        Permission::firstOrCreate(['name' => 'factures.encaisser']);
        Permission::firstOrCreate(['name' => 'factures.annuler']);
        // — Commissions / Salaires —
        Permission::firstOrCreate(['name' => 'commissions.payer']);
        Permission::firstOrCreate(['name' => 'commissions.cloturer']);
        Permission::firstOrCreate(['name' => 'commissions.exporter']);
        // — Logistique (workflow) —
        Permission::firstOrCreate(['name' => 'logistique.valider_chargement']);
        Permission::firstOrCreate(['name' => 'logistique.valider_reception']);
        Permission::firstOrCreate(['name' => 'logistique.cloturer']);

        // ── 2. Rôles + matrices de permissions ────────────────────────────────
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);
        $adminEntreprise = Role::firstOrCreate(['name' => 'admin_entreprise']);
        $manager = Role::firstOrCreate(['name' => 'manager']);
        $commerciale = Role::firstOrCreate(['name' => 'commerciale']);
        $comptable = Role::firstOrCreate(['name' => 'comptable']);
        Role::firstOrCreate(['name' => 'client']);
        Role::firstOrCreate(['name' => 'proprietaire']);
        Role::firstOrCreate(['name' => 'livreur']);

        $superAdmin->syncPermissions(Permission::all());

        $adminEntreprise->syncPermissions([
            // Personnes
            'clients.create',           'clients.read',           'clients.update',           'clients.delete',
            'prestataires.create',      'prestataires.read',      'prestataires.update',      'prestataires.delete',
            'livreurs.create',          'livreurs.read',          'livreurs.update',          'livreurs.delete',
            'proprietaires.create',     'proprietaires.read',     'proprietaires.update',     'proprietaires.delete',
            'pieces-identite.create',   'pieces-identite.read',   'pieces-identite.update',   'pieces-identite.delete',
            'pieces-identite.download', 'pieces-identite.valider', 'pieces-identite.rejeter',
            'imports-flotte.create',    'imports-flotte.read',
            // Véhicules
            'vehicules.create',         'vehicules.read',         'vehicules.update',         'vehicules.delete',
            'type-vehicules.create',    'type-vehicules.read',    'type-vehicules.update',    'type-vehicules.delete',
            'equipes-livraison.create', 'equipes-livraison.read', 'equipes-livraison.update', 'equipes-livraison.delete',
            'sites.create',             'sites.read',             'sites.update',             'sites.delete',
            // Commerce
            'produits.create',          'produits.read',          'produits.update',          'produits.delete',
            'produits.ajuster_stock',
            'categories.create',        'categories.read',        'categories.update',        'categories.delete',
            'options.create',           'options.read',           'options.update',           'options.delete',
            'type-produits.create',     'type-produits.read',     'type-produits.update',     'type-produits.delete',
            'packings.create',          'packings.read',          'packings.update',          'packings.delete',
            'ventes.create',            'ventes.read',            'ventes.update',            'ventes.delete',
            'ventes.qte.update',        'ventes.prix.update',
            'ventes.confirmer',         'ventes.annuler',         'ventes.demarrer_chargement', 'ventes.valider_chargement',
            'achats.create',            'achats.read',            'achats.update',            'achats.delete',
            'fournisseurs.create',      'fournisseurs.read',      'fournisseurs.update',      'fournisseurs.delete',
            'factures.create',          'factures.read',          'factures.update',          'factures.delete',
            'factures.encaisser',       'factures.annuler',
            'commissions.create',       'commissions.read',       'commissions.update',       'commissions.delete',
            'commissions.payer',        'commissions.cloturer',   'commissions.exporter',
            'cashback.create',          'cashback.read',          'cashback.update',          'cashback.delete',
            'pdv.create',               'pdv.read',               'pdv.update',               'pdv.delete',
            // Opérations
            'logistique.create',        'logistique.read',        'logistique.update',        'logistique.delete',
            'logistique.commission.verser',
            'logistique.valider_chargement', 'logistique.valider_reception', 'logistique.cloturer',
            'transferts.create',        'transferts.read',        'transferts.update',        'transferts.delete',
            'receptions.create',        'receptions.read',        'receptions.update',        'receptions.delete',
            // Finances
            'depenses.create',          'depenses.read',          'depenses.update',          'depenses.delete',
            'depenses.soumettre',       'depenses.valider',       'depenses.rejeter',         'depenses.annuler',
            'comptabilite.create',      'comptabilite.read',      'comptabilite.update',      'comptabilite.delete',
            'comptabilite.payer',
            'journal-financier.create', 'journal-financier.read', 'journal-financier.update', 'journal-financier.delete',
            // RH
            'rh-employes.create',       'rh-employes.read',       'rh-employes.update',       'rh-employes.delete',
            'rh-contrats.create',       'rh-contrats.read',       'rh-contrats.update',       'rh-contrats.delete',
            'rh-paie.create',           'rh-paie.read',           'rh-paie.update',           'rh-paie.delete',
            'rh-paie.validate',         'rh-paie.pay',            'rh-paie.close',
            // Administration
            'users.create',             'users.read',             'users.update',
            // Paramètres
            'parametres.read',          'parametres.update',
            'parametres-produits.read', 'parametres-produits.update',
            'parametres-depenses.read', 'parametres-depenses.update',
            'parametres-ventes.read',   'parametres-ventes.update',
            'parametres-systeme.read',  'parametres-systeme.update',
            'modules-metier.read',      'modules-metier.update',
        ]);

        $manager->syncPermissions([
            // Personnes
            'clients.create',           'clients.read',           'clients.update',
            'prestataires.create',      'prestataires.read',      'prestataires.update',
            'livreurs.create',          'livreurs.read',          'livreurs.update',
            'proprietaires.read',
            'pieces-identite.read',     'pieces-identite.download',
            // Véhicules
            'vehicules.create',         'vehicules.read',         'vehicules.update',
            'type-vehicules.create',    'type-vehicules.read',    'type-vehicules.update',
            'equipes-livraison.create', 'equipes-livraison.read', 'equipes-livraison.update',
            'sites.create',             'sites.read',             'sites.update',
            // Commerce
            'produits.read',            'produits.create',        'produits.update',
            'produits.ajuster_stock',
            'categories.read',          'categories.create',      'categories.update',
            'options.read',             'options.create',         'options.update',
            'type-produits.read',       'type-produits.create',   'type-produits.update',
            'packings.read',            'packings.create',        'packings.update',
            'ventes.create',            'ventes.read',            'ventes.update',
            'ventes.qte.update',        'ventes.prix.update',
            'ventes.confirmer',         'ventes.annuler',         'ventes.demarrer_chargement', 'ventes.valider_chargement',
            'achats.create',            'achats.read',            'achats.update',
            'fournisseurs.create',      'fournisseurs.read',      'fournisseurs.update',
            'factures.read',            'factures.create',
            'factures.encaisser',
            'commissions.read',
            'cashback.read',
            'pdv.create',               'pdv.read',               'pdv.update',
            // Opérations
            'logistique.create',        'logistique.read',        'logistique.update',
            'logistique.commission.verser',
            'logistique.valider_chargement', 'logistique.valider_reception', 'logistique.cloturer',
            'transferts.create',        'transferts.read',        'transferts.update',
            'receptions.create',        'receptions.read',        'receptions.update',
            // Finances
            'depenses.create',          'depenses.read',          'depenses.update',
            'depenses.soumettre',       'depenses.valider',       'depenses.rejeter', 'depenses.annuler',
            'comptabilite.read',        'comptabilite.payer',
            'journal-financier.read',
            // RH
            'rh-employes.create',       'rh-employes.read',       'rh-employes.update',
            'rh-contrats.create',       'rh-contrats.read',       'rh-contrats.update',
            'rh-paie.create',           'rh-paie.read',           'rh-paie.update',
            'rh-paie.validate',         'rh-paie.pay',
            // Administration
            'users.read',
            // Paramètres
            'parametres.read',
            'parametres-produits.read',
            'parametres-ventes.read',
        ]);

        $commerciale->syncPermissions([
            'clients.create',      'clients.read',      'clients.update',
            'prestataires.create', 'prestataires.read', 'prestataires.update',
            'livreurs.create',     'livreurs.read',     'livreurs.update',
            'proprietaires.read',
            'vehicules.create',    'vehicules.read',    'vehicules.update',
            'equipes-livraison.read',
            'sites.read',
            'produits.read',
            'categories.read',
            'options.read',
            'type-produits.read',
            'packings.read',
            'ventes.read',         'ventes.create',
            'ventes.confirmer',    'ventes.annuler',
            'factures.read',
            'cashback.read',
            'pdv.create',          'pdv.read',          'pdv.update',
        ]);

        $comptable->syncPermissions([
            'clients.read',           'prestataires.read',  'livreurs.read',
            'proprietaires.read',     'vehicules.read',     'equipes-livraison.read',
            'sites.read',             'produits.read',      'categories.read',    'options.read',    'type-produits.read',    'packings.read',
            'ventes.read',
            'factures.read',          'factures.encaisser',
            'commissions.read',       'commissions.payer',  'commissions.cloturer', 'commissions.exporter',
            'logistique.read',
            'logistique.commission.verser',
            'depenses.read',
            'comptabilite.read',      'comptabilite.payer',
            'journal-financier.read',
            'cashback.read',
            'rh-paie.read',
        ]);
    }

}
