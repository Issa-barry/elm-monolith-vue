# Sites

Un site est un lieu physique d'une organisation : agence, usine, dépôt, boutique… Chaque site
appartient à une seule organisation (`organization_id`). Décisions structurantes : ADR 0017.

## Quatre notions distinctes

| Notion | Champ | Répond à | Règle métier aujourd'hui |
|---|---|---|---|
| Type | `type` | Qu'est-ce que ce site ? | Libellé, suggestions à l'installation |
| Trésorerie principale | `is_central_tresorerie` | Vers où remontent les fonds ? | Oui (trésorerie) |
| Parent | `parent_id` | De quelle structure dépend-il ? | Aucune (affichage, blocage de suppression) |
| Désignation (siège national, régional…) | — | Quel rang administratif ? | N'existe pas encore |

### Type

`agence`, `usine` (couvre aussi « atelier »), `depot` (couvre aussi « entrepôt »), `boutique`,
`restaurant`, `autre`. Le type `siege` n'existe plus (ADR 0017) : les anciens sièges ont été
reclassés en `autre` et prennent leur vrai type depuis *Sites → Modifier*.

Libellé d'affichage (`Site::label`) : « {Type} de {Nom} » (« Agence de Matoto »), sauf si le nom
commence déjà par le type, et sauf pour le type `autre`, affiché sous son seul nom.

### Trésorerie principale

Libellé affiché depuis le 2026-10-02 : « Trésorerie principale » (badge sur la fiche et dans
la liste des sites). Les noms techniques gardent `central` (`is_central_tresorerie`,
`SiteCentralTresorerieResolver`) : seul le libellé a changé.

- **Au plus un par organisation**, garanti par `Site::saving()` : en désigner un retire le rôle à
  l'ancien.
- **Indépendant du type** : une agence peut être la trésorerie principale.
- **Désignation** : le premier site créé à l'installation devient la trésorerie principale. Aucune autre
  création de site ne la désigne automatiquement.
- **Changement** : interrupteur « Trésorerie principale » du formulaire Site (création et
  modification), réservé à `tresorerie.designer_principale` (par défaut : super administrateur).
  L'activer sur un autre site transfère le rôle, après confirmation. On ne la retire jamais en la
  décochant : l'interrupteur est verrouillé sur la trésorerie principale actuelle.
- **Utilisation** (`SiteCentralTresorerieResolver`) :
  - paiement d'une fiche sans agence (ADR 0009) ;
  - détenteur proposé par défaut pour un compte de trésorerie commun (ADR 0016) ;
  - destination unique des remises et des règlements inter-agences (ADR 0016).
- Sans trésorerie principale, ces opérations sont refusées avec un message explicite.

### Parent

`parent_id` pointe vers un autre site de l'organisation. Il se renseigne uniquement par l'import
CSV (colonne `site_parent_facultatif`, résolue par nom ou par code) et ne pilote ni les droits, ni la
trésorerie, ni les commissions. Un site qui a des enfants ne peut pas être supprimé.

## Autres champs

| Champ | Rôle |
|---|---|
| `nom` | Mis en casse titre |
| `code` | Généré automatiquement (`001`, `002`…), unique par organisation, modifiable en édition |
| `statut` | `active` (défaut), `inactive`, `suspendue` |
| `localisation`, `pays`, `ville`, `quartier`, `latitude`, `longitude` | Adresse (facultatif) |
| `telephone`, `email`, `description` | Contact (facultatif) |
| `commissions_active` | Si faux, la part de commission du site n'est plus générée (COMM-013) |
| `approbation_reception_logistique_obligatoire` | `null` = réglage de l'organisation |

## Rattachement des utilisateurs

`user_sites` : un utilisateur peut être rattaché à plusieurs sites, avec un `role` (`responsable`,
`employe`, sans effet applicatif aujourd'hui) et un site par défaut (`is_default`). Un non-admin ne
voit que les données de ses sites rattachés (`SiteScopeService`), sans héritage vers les sites
enfants.

### Périmètre de consultation et périmètre d'écriture (ADR 0025)

| Périmètre | Qui voit / agit sur toutes les agences | Source unique |
|---|---|---|
| **Consultation** (listes, détails, recherche, exports, statistiques) | administrateur, **ou** rôle ayant la permission `sites.lecture_toutes_agences` | `User::voitToutesLesAgences()`, `SiteScopeService::accessibleSiteIds()` |
| **Écriture** (créer, modifier, supprimer, valider, payer, réceptionner…) | administrateur seulement ; sinon agences de rattachement | `User::isAdmin()`, `SiteScopeService::assignedSiteIds()`, policies |

- La permission se coche dans Rôles & Permissions → Sites → « Périmètre de consultation ». Elle
  n'ouvre aucun écran : « Lire » sur la ressource reste requis. Elle ne dépend ni du libellé ni du
  trinôme du rôle.
- Elle n'accorde aucune écriture : un rôle qui a aussi « Créer » ou « Modifier » continue d'agir
  uniquement dans ses agences de rattachement.
- Tout nouvel écran de liste applique `voitToutesLesAgences()` pour la lecture et `isAdmin()` pour
  l'écriture — jamais `isAdmin()` pour décider de ce qui est affiché.

## Import CSV

Le type est reconnu par son libellé exact (« Agence », « Usine », « Dépôt »…). « Siège » reste
accepté comme alias historique et s'importe en `autre`, avec une normalisation signalée. L'import ne
désigne jamais de trésorerie principale.
