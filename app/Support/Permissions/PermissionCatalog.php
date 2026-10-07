<?php

namespace App\Support\Permissions;

/**
 * Source de vérité unique des ressources/actions CRUD et des permissions "standalone"
 * (hors matrice CRUD) de l'application. Remplace trois listes qui avaient divergé entre
 * elles : `RoleController::RESOURCES` (matrice éditable dans /backoffice/roles, 31
 * ressources), `RolesAndPermissionsSeeder::RESOURCES` (38 ressources, la plus complète —
 * base de cette classe) et `User::permissionsMap()` (37 ressources, sans `tresorerie`).
 *
 * Les contrôleurs `Role\*`, `RolesAndPermissionsSeeder` et `User::permissionsMap()` consomment
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
     * que `Role\EditRoleController` puisse les afficher dans une section dédiée de la
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
        'communications.read' => 'Communications — consulter le journal des messages (SMS/WhatsApp)',
        'communications.manage' => 'Communications — configurer les règles de notification (SMS/WhatsApp)',
        'pieces-identite.download' => "Pièces d'identité — télécharger",
        'pieces-identite.valider' => "Pièces d'identité — valider",
        'pieces-identite.rejeter' => "Pièces d'identité — rejeter",
        'comptabilite.payer' => 'Comptabilité — payer',
        'tresorerie.envoyer' => "Trésorerie — envoyer un mouvement de fonds (entre agences, ou versement de la caisse d'un autre agent)",
        'tresorerie.recevoir' => 'Trésorerie — confirmer la réception (versements de caisse et mouvements entre agences)',
        'tresorerie.annuler' => 'Trésorerie — annuler un mouvement',
        'tresorerie.rejeter' => 'Trésorerie — contester un mouvement reçu',
        'tresorerie.confirmer_retour' => 'Trésorerie — confirmer un retour',
        'tresorerie.verser' => "Trésorerie — déclencher un versement de caisse vers l'agence",
        'tresorerie.gerer_soldes_ouverture' => "Trésorerie — gérer les soldes d'ouverture",
        'tresorerie.valider_supports' => 'Trésorerie — valider un support (caisse, banque, Mobile Money)',
        'tresorerie.designer_principale' => 'Trésorerie — désigner la trésorerie principale (site central des flux inter-agences)',
        'tresorerie.exporter' => 'Trésorerie — exporter',
        'achats.valider' => 'Achats — valider un bon de commande (dans la limite du plafond du rôle, Paramètres → Achats)',
        'achats.annuler' => 'Achats — annuler un bon de commande ou clôturer son reliquat',
        'depenses.soumettre' => 'Dépenses — soumettre',
        'depenses.valider' => 'Dépenses — valider',
        'depenses.rejeter' => 'Dépenses — rejeter',
        'depenses.annuler' => 'Dépenses — annuler',
        'produits.ajuster_stock' => 'Produits — ajuster le stock',
        'ventes.confirmer' => 'Ventes — confirmer',
        'ventes.precommander' => 'Ventes — créer une précommande (acompte initial compris)',
        'ventes.preparer' => 'Ventes — lancer et valider la préparation d’une précommande',
        'ventes.valider_retrait' => 'Ventes — valider le retrait d’une précommande',
        'ventes.changer_mode_remise' => 'Ventes — changer le mode de remise d’une précommande (retrait ↔ livraison) avant le chargement',
        'ventes.rembourser' => 'Ventes — rembourser un client (trop-perçu, acomptes d’une précommande annulée)',
        'ventes.annuler_precommande' => 'Ventes — annuler une précommande avant sa préparation',
        'ventes.annuler_precommande_preparee' => 'Ventes — annuler une précommande en préparation ou préparée (procédure renforcée)',
        'ventes.annuler' => 'Ventes — annuler',
        'ventes.demarrer_chargement' => 'Ventes — démarrer le chargement',
        'ventes.valider_chargement' => 'Ventes — valider le chargement',
        'ventes.valider_reception' => 'Ventes — valider la réception',
        'ventes.enregistrer_retour' => 'Ventes — enregistrer un retour de livraison',
        'ventes.annuler_exceptionnel' => 'Ventes — annulation exceptionnelle (commande saisie par erreur, même encaissée)',
        'ventes.exporter' => 'Ventes — exporter',
        'factures.encaisser' => 'Factures — encaisser',
        'factures.encaisser_autre_agence' => "Factures — encaisser dans son agence une commande d'une autre agence",
        'factures.annuler' => 'Factures — annuler',
        'commissions.payer' => 'Commissions — payer',
        'commissions.cloturer' => 'Commissions — clôturer',
        'commissions.exporter' => 'Commissions — exporter',
        'logistique.valider_chargement' => 'Logistique — valider le chargement',
        'logistique.valider_reception' => 'Logistique — valider la réception',
        'logistique.cloturer' => 'Logistique — clôturer',
        'rapports.read_own' => 'Rapports — consulter « Ma situation » (ses propres ventes, encaissements et caisse)',
        'rapports.read' => "Rapports — consulter le rapport d'activité de ses agences (tous les agents)",
    ];

    /**
     * Regroupement métier des ressources (matrice CRUD) et des permissions STANDALONE, utilisé
     * uniquement pour l'affichage de l'écran `/backoffice/roles/{role}/edit` (Role\EditRoleController
     * → Roles/Edit.vue). Un domaine rassemble ses paramètres dédiés (ex. `parametres-ventes`) avec
     * ses ressources et ses actions de workflow, pour que l'admin configure un métier au même
     * endroit plutôt que de le retrouver éclaté entre "Paramètres" et "Permissions spécifiques".
     * Ordre des clés calé sur le menu latéral de l'application (AppSidebar.vue) : Ventes → Achats
     * → Contacts → Véhicules → Produits → Imports → Logistique → Sites → Finance → Dépenses → RH
     * → Administration → Communications → Pièces d'identité.
     *
     * `standalone` regroupe chaque permission de workflow par sous-processus métier (ex. "Cycle de
     * vente" / "Facturation" dans le domaine Ventes) plutôt qu'en une liste plate — décision du
     * 13/09/2026 pour que la section "Actions spécifiques" reste lisible à mesure qu'elle grossit.
     * Jamais une seconde matrice créer/lire/modifier/supprimer : simplement des cases à cocher
     * réparties sous des intitulés de sous-groupe (Roles/Edit.vue masque l'intitulé quand un
     * domaine n'a qu'un seul sous-groupe, pour ne pas répéter le nom du domaine juste au-dessus).
     *
     * N'influence ni les noms de permissions ni les autorisations : `UpdateRoleController` valide
     * toujours contre `permissions.name` en base, indépendamment de ce regroupement. Chaque clé de
     * RESOURCES/STANDALONE doit apparaître dans exactement un domaine — couverture vérifiée par
     * `PermissionCatalogTest::test_domains_cover_every_resource_exactly_once()` et
     * `test_domains_cover_every_standalone_permission_exactly_once()`.
     */
    public const DOMAINS = [
        'ventes' => [
            'label' => 'Ventes',
            'resources' => ['parametres-ventes', 'ventes', 'factures', 'cashback', 'pdv'],
            'standalone' => [
                'Cycle de vente' => [
                    'ventes.confirmer', 'ventes.annuler', 'ventes.demarrer_chargement',
                    'ventes.valider_chargement', 'ventes.valider_reception', 'ventes.enregistrer_retour',
                    'ventes.annuler_exceptionnel',
                    'ventes.qte.update', 'ventes.prix.update',
                ],
                'Précommandes' => [
                    'ventes.precommander', 'ventes.preparer', 'ventes.valider_retrait', 'ventes.changer_mode_remise', 'ventes.rembourser',
                    'ventes.annuler_precommande', 'ventes.annuler_precommande_preparee',
                ],
                'Facturation' => ['factures.encaisser', 'factures.encaisser_autre_agence', 'factures.annuler'],
                'Export' => ['ventes.exporter'],
            ],
        ],
        'achats' => [
            'label' => 'Achats & Fournisseurs',
            'resources' => ['achats', 'fournisseurs'],
            'standalone' => [
                'Bons de commande' => ['achats.valider', 'achats.annuler'],
            ],
        ],
        'contacts' => [
            'label' => 'Clients & Contacts',
            'resources' => ['clients', 'prestataires', 'livreurs', 'proprietaires'],
            'standalone' => [],
        ],
        'vehicules' => [
            'label' => 'Véhicules & Flotte',
            'resources' => ['vehicules', 'type-vehicules'],
            'standalone' => [],
        ],
        'produits' => [
            'label' => 'Produits & Stock',
            'resources' => ['parametres-produits', 'produits', 'categories', 'options', 'type-produits', 'packings'],
            'standalone' => [
                'Stock' => ['produits.ajuster_stock'],
            ],
        ],
        'imports' => [
            'label' => 'Imports',
            'resources' => [],
            'standalone' => [
                'Flotte' => ['imports-flotte.create', 'imports-flotte.read'],
                'Véhicules' => ['imports-vehicules-maj.create', 'imports-vehicules-maj.read'],
                'Produits' => ['imports-produits.create', 'imports-produits.read'],
            ],
        ],
        'logistique' => [
            'label' => 'Logistique',
            'resources' => ['logistique', 'transferts', 'receptions', 'equipes-livraison'],
            'standalone' => [
                'Chargement / Réception' => ['logistique.valider_chargement', 'logistique.valider_reception'],
                'Commissions' => ['logistique.commission.verser', 'logistique.cloturer'],
            ],
        ],
        'sites' => [
            'label' => 'Sites',
            'resources' => ['sites'],
            'standalone' => [],
        ],
        'finance' => [
            'label' => 'Finance & Comptabilité',
            'resources' => ['comptabilite', 'journal-financier', 'tresorerie', 'commissions'],
            'standalone' => [
                // Libellés métier des clés historiques (décision du 27/09/2026) : envoyer / recevoir /
                // rejeter servent aussi aux mouvements entre agences — jamais renommées.
                'Versements et mouvements de fonds' => [
                    'tresorerie.verser', 'tresorerie.envoyer', 'tresorerie.recevoir', 'tresorerie.rejeter',
                    'tresorerie.annuler', 'tresorerie.confirmer_retour',
                ],
                'Trésorerie' => [
                    'tresorerie.gerer_soldes_ouverture', 'tresorerie.valider_supports', 'tresorerie.exporter',
                    'tresorerie.designer_principale',
                ],
                'Comptabilité' => ['comptabilite.payer'],
                'Commissions' => ['commissions.payer', 'commissions.cloturer', 'commissions.exporter'],
            ],
        ],
        'depenses' => [
            'label' => 'Dépenses',
            'resources' => ['parametres-depenses', 'depenses'],
            'standalone' => [
                'Dépenses' => ['depenses.soumettre', 'depenses.valider', 'depenses.rejeter', 'depenses.annuler'],
            ],
        ],
        'rapports' => [
            'label' => 'Rapports',
            'resources' => [],
            'standalone' => [
                'Rapports' => ['rapports.read_own', 'rapports.read'],
            ],
        ],
        'rh' => [
            'label' => 'RH & Paie',
            'resources' => ['rh-employes', 'rh-contrats', 'rh-paie'],
            'standalone' => [
                'Paie' => ['rh-paie.validate', 'rh-paie.pay', 'rh-paie.close'],
            ],
        ],
        'administration' => [
            'label' => 'Administration & Système',
            'resources' => ['users', 'parametres', 'parametres-systeme', 'modules-metier'],
            'standalone' => [],
        ],
        'communications' => [
            'label' => 'Communications',
            'resources' => [],
            'standalone' => [
                'Communications' => ['communications.read', 'communications.manage'],
            ],
        ],
        'pieces-identite' => [
            'label' => "Pièces d'identité",
            'resources' => ['pieces-identite'],
            'standalone' => [
                "Pièces d'identité" => ['pieces-identite.download', 'pieces-identite.valider', 'pieces-identite.rejeter'],
            ],
        ],
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

    /**
     * DOMAINS restreint aux ressources effectivement visibles pour l'acteur courant (ex. `users`
     * retiré pour un non-super-admin, cf. Role\EditRoleController) — les domaines qui se
     * retrouvent sans aucune ressource ni permission standalone sont supprimés du résultat.
     *
     * @param  list<string>  $visibleResources
     * @return array<string, array{label: string, resources: list<string>, standalone: array<string, list<string>>}>
     */
    public static function domainsFor(array $visibleResources): array
    {
        $domains = [];
        foreach (self::DOMAINS as $key => $domain) {
            $resources = array_values(array_intersect($domain['resources'], $visibleResources));
            if ($resources === [] && $domain['standalone'] === []) {
                continue;
            }
            $domains[$key] = [
                'label' => $domain['label'],
                'resources' => $resources,
                'standalone' => $domain['standalone'],
            ];
        }

        return $domains;
    }
}
