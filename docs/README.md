# Documentation d'EAU LA MAMAN

Point d'entrée de la documentation du back-office (`elm-monolithe`). Les documents techniques sont
rangés dans des sous-dossiers ; les documents métier restent à la racine de `docs/`, où le code et
les ADR les référencent.

Chaque document technique indique en tête son **statut** :

- **en place** : décrit ce qui fonctionne aujourd'hui ;
- **en cours** : en train d'être mis en place, susceptible de changer ;
- **envisagé** : décision ou projet, rien n'est encore opérationnel.

## Comprendre le projet

- [Vue d'ensemble de l'architecture](architecture/vue-ensemble.md)
- [Décisions d'architecture et métier (ADR)](adr/README.md)

## Installer

- [Installer l'environnement local](installation/environnement-local.md)
- [Configuration : variables d'environnement](installation/configuration.md)

## Développer

- **[Standards de développement](developpement/standards.md)** : référence unique des règles
  d'interface et des règles transverses, à lire avant toute modification
- [Workflow Git et versions](developpement/workflow-git.md)
- [Intégration continue et déploiement (GitHub Actions)](developpement/ci-cd.md)
- [Tests : PHPUnit, Vitest, Playwright](developpement/tests.md)
- [Contrôleurs à action unique](refactor-controllers-mono-action.md)
- [Filtres de liste (`DataFilters`)](filters.md)
- [Filtres de période : convention obligatoire](periodes.md) · [audit du 04/11/2026](audit-filtres-periodes.md)
- [Factures et bons PDF : modèle officiel](documents-pdf.md)
- [Cartes de synthèse et informations contextuelles](ui-cartes-et-informations.md)
- [Thèmes](theming.md)
- [PWA](pwa.md)

## API

- [Authentification (Sanctum)](api-auth-contract.md)
- [Espace client (Nuxt, mobile)](api-espace-client-contract.md)
- [Espace parrain](api-espace-parrain-contract.md)
- Exports OpenAPI : [openapi/](openapi/) (`composer openapi:export` pour les régénérer)
- Documentation interactive : `/docs/api` (API client et mobile) et `/docs/vitrine` (site, serveur à
  serveur), au format JSON sur `/docs/api.json` et `/docs/vitrine.json`. Servie seulement si
  `API_DOCS_ENABLED=true` (désactivée par défaut, et en production) ; hors environnement `local`,
  il faut être connecté avec un compte back-office.

## Déployer et exploiter

- [Déploiement sur Hostinger](deploiement/hostinger.md) (hébergement actuel)
- [Organisation et accès de démonstration](installation/demonstration.md)

## Règles métier, par domaine

**Ventes et encaissements**

- [Encaissements de vente](encaissements.md)
- [Contrôle des encaissements](controle-encaissements.md)
- [Précommandes](precommandes.md)
- [Régularisation d'une vente après encaissement](regularisation-vente.md)
- [Retour de livraison avant encaissement](retour-commande.md)
- [Annulation exceptionnelle d'une commande](annulation-exceptionnelle.md)
- [Client grossiste](grossiste.md)
- [Cashback client](cashback.md)
- [Situation véhicule : activité commerciale](vehicule-situation-ventes.md)

**Clients, contacts et comptes**

- [Référentiel Contacts](contacts.md)
- [Identité client ↔ personne](identite-client-personne.md)
- [Rattachement des comptes aux parrains et consultants](rattachement-comptes.md)
- [Filtre période comptable de l'espace client](filtre-periode-comptable.md)

**Logistique, stock et véhicules**

- [Filtres des transferts et réceptions](logistique-filtres.md)
- [Retour au site source sur un transfert](retour-transfert.md)
- [Disponibilité et alerte de stock faible](stock-alertes.md)
- [Date métier des mouvements de stock](stock-mouvements-date.md)
- [Capacités de chargement des véhicules](capacites-vehicule.md)
- [Parrainage véhicule](parrainage-vehicule.md)
- [Import de mise à jour des véhicules](import-vehicules-maj.md)
- [Dashboard mobile : QR d'identité et scanner](scanner-dashboard-mobile.md)

**Commissions**

- [Commissions : vente et logistique](commissions.md)

**Achats et dépenses**

- [Achats : bons de commande fournisseurs](achats.md)
- [Validation des dépenses](depenses-validation.md)
- [Visibilité des dépenses par agence](depenses-visibilite.md)
- [Concernés d'un type de dépense](depenses-concernes.md)
- [Filtres de la liste des dépenses](depenses-filtres.md)
- [Dépense de carburant : commande de référence](depenses-carburant-commande.md)

**Trésorerie et comptabilité**

- [Trésorerie inter-agences](tresorerie-inter-agences.md)
- [Dictionnaire de données de la comptabilité](data-dictionary-compta.md)

**RH et paie**

- [Paie des salariés](paie.md)
- [Primes et avantages](primes-avantages.md)
- [Transmission de paie RH → Comptabilité](transmission-paie.md)
- [Contrats de travail générés](rh-contrat-pdf.md)
- [Départ d'un salarié](rh-depart-salarie.md)
- [Création d'un employé : identité existante](rh-employes-identite.md)
- [Import des dossiers et contrats RH](import-dossiers-rh.md)
- [Fiche agent](fiche-agent.md)

**Rapports**

- [Rapport d'activité, rapport par agence et « Ma situation »](rapports.md)

**Organisation, agences et communications**

- [Sites (agences)](sites.md)
- [Références métier](references-metier.md)
- [Monitoring des communications (SMS, WhatsApp)](communications.md)
- [Notifications transactionnelles](notifications-transactionnelles.md)

## Contribuer à la documentation

- Toute modification d'une règle métier, d'une procédure (installation, configuration,
  déploiement, CI) ou de l'architecture met à jour le document concerné **dans la même pull
  request**.
- Toute décision structurante a son ADR : [comment en créer un](adr/README.md#créer-un-adr).
- Ne documenter que ce qui est vérifié, et indiquer le statut (en place, en cours, envisagé).
- Aucun mot de passe, jeton ni clé dans la documentation.
- Un nouveau document est ajouté à ce sommaire.
