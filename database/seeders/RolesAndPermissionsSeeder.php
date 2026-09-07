<?php

namespace Database\Seeders;

use App\Support\Permissions\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Ne crée JAMAIS de compte de démo, ni d'organisation — sécurité indépendante d'APP_ENV,
     * garantie par le fait que ce seeder ne touche plus qu'à des données globales (permissions,
     * rôles système `organization_id IS NULL`). C'est précisément ce qui le rend sûr à lancer
     * automatiquement à chaque déploiement de production (`db:seed --class=RolesAndPermissionsSeeder
     * --force`, cf. deploy-hostinger-admin.yml / deploy-hostinger-formation.yml) : sur une instance
     * on-premise neuve, `organizations` doit rester vide tant que `/install` n'a pas été complété
     * (cf. InstallationService::isLocked()) — un seeder de CI/CD ne doit jamais créer de donnée
     * métier (organisation, site, utilisateur).
     *
     * Le seul compte réel de mise en prod est créé par `php artisan app:install` / `/install` (cf.
     * InstallApp, InstallationService::install()), jamais par un seeder. L'organisation de
     * démonstration "elm", elle, est créée par ElmDemoOrganizationSeeder, appelée uniquement par
     * DatabaseSeeder (dev local / CI de tests) — jamais par le pipeline de déploiement.
     */
    public function run(): void
    {
        self::seedRolesEtPermissions();
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
        // Source de vérité unique : App\Support\Permissions\PermissionCatalog (CRUD +
        // standalone) — ne plus lister les permissions à la main ici, cf. sa docblock.
        foreach (PermissionCatalog::allPermissionNames() as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        // ── 2. Rôles + matrices de permissions ────────────────────────────────
        // Rôles système (organization_id NULL, partagés par toutes les organisations) — seul
        // super_admin est protégé contre le renommage/suppression (règle centralisée dans
        // RoleController, jamais une colonne : cf. sa docblock). Les 7 autres sont désormais des
        // rôles métier ordinaires, modifiables/supprimables comme n'importe quel rôle créé via le
        // CRUD, simplement pré-remplis ici pour ne pas partir d'une organisation vide.
        // Le label est réassigné à chaque exécution (idempotent) plutôt que seulement à la
        // création, pour corriger aussi les rôles déjà en base avant l'ajout de cette colonne.
        $labels = [
            'super_admin' => 'Super administrateur',
            'admin_entreprise' => 'Administrateur entreprise',
            'manager' => 'Manager',
            'commerciale' => 'Commerciale',
            'comptable' => 'Comptable',
            'client' => 'Client',
            'proprietaire' => 'Propriétaire',
            'livreur' => 'Livreur',
        ];
        foreach ($labels as $name => $label) {
            Role::updateOrCreate(['name' => $name, 'organization_id' => null], ['label' => $label]);
        }

        $superAdmin = Role::whereNull('organization_id')->where('name', 'super_admin')->firstOrFail();
        $adminEntreprise = Role::whereNull('organization_id')->where('name', 'admin_entreprise')->firstOrFail();
        $manager = Role::whereNull('organization_id')->where('name', 'manager')->firstOrFail();
        $commerciale = Role::whereNull('organization_id')->where('name', 'commerciale')->firstOrFail();
        $comptable = Role::whereNull('organization_id')->where('name', 'comptable')->firstOrFail();

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
            'imports-vehicules-maj.create', 'imports-vehicules-maj.read',
            'type-vehicules.create',    'type-vehicules.read',    'type-vehicules.update',    'type-vehicules.delete',
            'equipes-livraison.create', 'equipes-livraison.read', 'equipes-livraison.update', 'equipes-livraison.delete',
            'sites.create',             'sites.read',             'sites.update',             'sites.delete',
            // Commerce
            'produits.create',          'produits.read',          'produits.update',          'produits.delete',
            'produits.ajuster_stock',
            'imports-produits.create',  'imports-produits.read',
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
            'tresorerie.create',        'tresorerie.read',        'tresorerie.update',        'tresorerie.delete',
            'tresorerie.envoyer',       'tresorerie.recevoir',    'tresorerie.annuler',       'tresorerie.rejeter',
            'tresorerie.confirmer_retour',
            'tresorerie.gerer_soldes_ouverture', 'tresorerie.exporter',
            // RH
            'rh-employes.create',       'rh-employes.read',       'rh-employes.update',       'rh-employes.delete',
            'rh-contrats.create',       'rh-contrats.read',       'rh-contrats.update',       'rh-contrats.delete',
            'rh-paie.create',           'rh-paie.read',           'rh-paie.update',           'rh-paie.delete',
            'rh-paie.validate',         'rh-paie.pay',            'rh-paie.close',
            // Administration
            'users.create',             'users.read',             'users.update',
            'communications.read',
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
            'imports-vehicules-maj.create', 'imports-vehicules-maj.read',
            'type-vehicules.create',    'type-vehicules.read',    'type-vehicules.update',
            'equipes-livraison.create', 'equipes-livraison.read', 'equipes-livraison.update',
            'sites.create',             'sites.read',             'sites.update',
            // Commerce
            'produits.read',            'produits.create',        'produits.update',
            'produits.ajuster_stock',
            'imports-produits.create',  'imports-produits.read',
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
            'tresorerie.create',        'tresorerie.read',
            'tresorerie.envoyer',       'tresorerie.recevoir',
            // RH
            'rh-employes.create',       'rh-employes.read',       'rh-employes.update',
            'rh-contrats.create',       'rh-contrats.read',       'rh-contrats.update',
            'rh-paie.create',           'rh-paie.read',           'rh-paie.update',
            'rh-paie.validate',         'rh-paie.pay',
            // Administration
            'users.read',
            'communications.read',
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
            'tresorerie.create',      'tresorerie.read',        'tresorerie.update',
            'tresorerie.envoyer',     'tresorerie.recevoir',    'tresorerie.annuler',     'tresorerie.rejeter',
            'tresorerie.confirmer_retour',
            'tresorerie.gerer_soldes_ouverture', 'tresorerie.exporter',
            'cashback.read',
            'rh-paie.read',
        ]);
    }
}
