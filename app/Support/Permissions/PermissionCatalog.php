<?php

namespace App\Support\Permissions;

/**
 * Source de vérité unique des ressources/actions CRUD et des permissions "standalone"
 * (hors matrice CRUD) de l'application. Remplace trois listes qui avaient divergé entre
 * elles : `RoleController::RESOURCES` (matrice éditable dans /backoffice/roles, 31
 * ressources), `RolesAndPermissionsSeeder::RESOURCES` (38 ressources, la plus complète —
 * base de cette classe) et `User::permissionsMap()` (37 ressources, sans `tresorerie`).
 *
 * `RoleController`, `RolesAndPermissionsSeeder` et `User::permissionsMap()` consomment
 * désormais tous cette classe — plus aucune de ces trois listes n'est dupliquée ailleurs.
 *
 * Convention de nommage (déjà en usage, figée ici) : `resource.action` en snake_case anglais
 * pour les 4 actions CRUD (RESOURCES × ACTIONS) ; `resource.verbe_metier` en français pour une
 * action de workflow sans équivalent CRUD (STANDALONE, ex. `depenses.valider`). Ne jamais
 * renommer une permission existante pour "corriger" une incohérence de convention : les rôles
 * déjà configurés en base la référencent par son nom exact.
 */
final class PermissionCatalog
{
    public const RESOURCES = [
        // Personnes
        'clients', 'prestataires', 'livreurs', 'proprietaires', 'pieces-identite',
        // Véhicules & logistique terrain
        'vehicules', 'type-vehicules', 'equipes-livraison', 'sites',
        // Commerce
        'produits', 'categories', 'options', 'type-produits', 'packings', 'ventes', 'achats', 'fournisseurs', 'factures', 'commissions', 'cashback', 'pdv',
        // Opérations
        'logistique', 'transferts', 'receptions',
        // Finances
        'depenses', 'comptabilite', 'journal-financier', 'tresorerie',
        // RH
        'rh-employes', 'rh-contrats', 'rh-paie',
        // Administration
        'users',
        // Paramètres
        'parametres', 'parametres-produits', 'parametres-depenses', 'parametres-ventes', 'parametres-systeme', 'modules-metier',
    ];

    public const ACTIONS = ['create', 'read', 'update', 'delete'];

    /**
     * Permissions "workflow" hors matrice CRUD standard, avec leur libellé humain — pour
     * que `RoleController::edit()` puisse les afficher dans une section dédiée de la
     * matrice au lieu de les laisser invisibles/non-éditables (cf. audit § totalPerms).
     */
    public const STANDALONE = [
        'logistique.commission.verser' => 'Logistique — verser une commission',
        'ventes.qte.update' => 'Ventes — modifier une quantité déjà chargée',
        'ventes.prix.update' => 'Ventes — modifier un prix unitaire',
        'rh-paie.validate' => 'Paie — valider une période',
        'rh-paie.pay' => 'Paie — payer une période',
        'rh-paie.close' => 'Paie — clôturer une période',
        'imports-flotte.create' => 'Import flotte — lancer un import',
        'imports-flotte.read' => 'Import flotte — consulter les imports',
        'imports-vehicules-maj.create' => 'Import véhicules — lancer une mise à jour en masse',
        'imports-vehicules-maj.read' => 'Import véhicules — consulter les imports',
        'imports-produits.create' => 'Import produits — lancer un import',
        'imports-produits.read' => 'Import produits — consulter les imports',
        'pieces-identite.download' => "Pièces d'identité — télécharger",
        'pieces-identite.valider' => "Pièces d'identité — valider",
        'pieces-identite.rejeter' => "Pièces d'identité — rejeter",
        'comptabilite.payer' => 'Comptabilité — payer',
        'tresorerie.envoyer' => 'Trésorerie — envoyer un mouvement',
        'tresorerie.recevoir' => 'Trésorerie — recevoir un mouvement',
        'tresorerie.annuler' => 'Trésorerie — annuler un mouvement',
        'tresorerie.rejeter' => 'Trésorerie — rejeter un mouvement',
        'tresorerie.confirmer_retour' => 'Trésorerie — confirmer un retour',
        'tresorerie.gerer_soldes_ouverture' => "Trésorerie — gérer les soldes d'ouverture",
        'tresorerie.exporter' => 'Trésorerie — exporter',
        'depenses.soumettre' => 'Dépenses — soumettre',
        'depenses.valider' => 'Dépenses — valider',
        'depenses.rejeter' => 'Dépenses — rejeter',
        'depenses.annuler' => 'Dépenses — annuler',
        'produits.ajuster_stock' => 'Produits — ajuster le stock',
        'ventes.confirmer' => 'Ventes — confirmer',
        'ventes.annuler' => 'Ventes — annuler',
        'ventes.demarrer_chargement' => 'Ventes — démarrer le chargement',
        'ventes.valider_chargement' => 'Ventes — valider le chargement',
        'factures.encaisser' => 'Factures — encaisser',
        'factures.annuler' => 'Factures — annuler',
        'commissions.payer' => 'Commissions — payer',
        'commissions.cloturer' => 'Commissions — clôturer',
        'commissions.exporter' => 'Commissions — exporter',
        'logistique.valider_chargement' => 'Logistique — valider le chargement',
        'logistique.valider_reception' => 'Logistique — valider la réception',
        'logistique.cloturer' => 'Logistique — clôturer',
    ];

    /**
     * @return list<string>
     */
    public static function crudPermissionNames(): array
    {
        $names = [];
        foreach (self::RESOURCES as $resource) {
            foreach (self::ACTIONS as $action) {
                $names[] = "{$resource}.{$action}";
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public static function allPermissionNames(): array
    {
        return [...self::crudPermissionNames(), ...array_keys(self::STANDALONE)];
    }

    public static function totalCount(): int
    {
        return count(self::allPermissionNames());
    }
}
