# Création d'un employé — identité existante

Point d'entrée : `EmployeController::store()` (écran RH « Nouvel employé »). La validation d'un
compte en attente (`AccountValidationService`) crée ou rattache aussi une fiche Employe, par
`personne_id`.

## Modèle

- **Personne** : identité civile unique par organisation (nom, prénom, téléphone, email).
  Clé de rapprochement : `telephone_normalise`, unique par `(organization_id, telephone_normalise)`.
- **Employe** : dossier RH (matricule, type, statut, site/fonction via `EmployeAffectationService`).
  Référence sa Personne, ne recopie jamais l'identité.
- **User** : compte de connexion, optionnel. Un employé n'a jamais besoin d'un compte pour exister.
- **Client, Propriétaire, Livreur, Fournisseur, Prestataire, Parrain** : autres rôles portés par la
  même Personne. Le client garde en plus ses propres colonnes d'identité
  (cf. `identite-client-personne.md`).

La création d'un employé ne crée **jamais** de compte utilisateur.

## Règles à la création

| Situation | Comportement |
|---|---|
| Téléphone inconnu dans l'organisation | Nouvelle Personne + Employe. |
| Pas de téléphone saisi | Refusé depuis le 01/11/2026 : le téléphone est obligatoire à la création (voir « Parcours Nouvel employé »). |
| Téléphone d'un utilisateur, client, propriétaire, livreur… | La Personne existante est réutilisée : un seul individu, plusieurs rôles. |
| Personne réutilisée sans nom/prénom/email (livreur créé au seul téléphone, client avec `nom_complet` seul) | Les champs **vides** sont complétés avec la saisie RH. |
| Personne réutilisée avec un nom/prénom/email déjà renseigné et différent | L'identité existante est conservée ; la saisie RH est ignorée pour ces champs. |
| Personne déjà employée (fiche non supprimée) | Refus : « Cette personne est déjà enregistrée comme employé (…, matricule …) ». |
| Ancien employé supprimé (soft delete) | Réembauche autorisée : nouvelle fiche, nouveau matricule. |
| Email déjà porté par un autre employé de l'organisation | Refus (comparaison insensible à la casse). |
| Site ou fonction d'une autre organisation | Refus (`site_id`, `fonction_rh_id`), en création comme en modification. |

La création est transactionnelle : la Personne est verrouillée le temps du contrôle « déjà
employé », deux créations simultanées avec le même téléphone ne peuvent pas aboutir toutes les deux.
Si un même numéro inconnu est enregistré au même instant par deux utilisateurs, la contrainte
unique `(organization_id, telephone_normalise)` refuse la seconde personne : l'utilisateur reçoit
« Ce numéro vient d'être enregistré par quelqu'un d'autre. Cliquez de nouveau sur « Rechercher » »
(jamais une erreur technique).

## Parcours « Nouvel employé » : le téléphone d'abord (01/11/2026)

1. **Numéro de téléphone** (sélecteur de pays, Guinée +224 par défaut) puis **« Rechercher »**
   (`GET /backoffice/employes/recherche-telephone`, `RechercheEmployeParTelephone`, droit de créer
   un employé, organisation de l'utilisateur seulement). Le reste du formulaire n'apparaît
   qu'après la recherche.
2. Le numéro est reconnu quel que soit son format : `+224 622 60 26 93`, `00224622602693`,
   `224622602693`, `622602693` ou `0622602693` (avec le pays choisi) → `+224622602693`, validé par
   libphonenumber. Même clé que l'enregistrement (`telephone_normalise`, chiffres seuls).
3. Résultat :

| Résultat | Affichage | Suite |
|---|---|---|
| Un dossier employé existe (actif, suspendu ou sorti) | Orange : « Un employé existe déjà avec ce numéro » — nom, matricule, statut, agence | **Aucune création** : « Ouvrir la fiche » / « Compléter le dossier » (selon les droits et l'agence). Hors de ses agences, l'existence est dite sans lien. |
| Plusieurs dossiers employés (données anciennes) | « Plusieurs dossiers … choisissez le bon » + la liste | Aucun choix automatique, aucune création |
| Numéro d'une personne connue sous un autre rôle (livreur, utilisateur, propriétaire…) | Bleu : nom et rôles de la personne | Création possible, rattachée à cette personne ; nom et prénom repris, non modifiables ici |
| Numéro inconnu | Vert : « Aucun employé trouvé. Vous pouvez créer un nouveau dossier. » | Création, numéro déjà rempli |
| Ancien dossier supprimé | Mention « ancien dossier archivé » | Réembauche possible (règle ci-dessus) |

- **Téléphone obligatoire à la création** (décision du 01/11/2026, auparavant facultatif) : pas de
  création sans passer par la recherche du numéro ; le serveur refuse une création sans
  téléphone (« Le numéro de téléphone est obligatoire : il permet de vérifier que l'employé n'est
  pas déjà enregistré »). En modification, il reste facultatif : une fiche ancienne sans numéro
  reste modifiable (et l'effacement garde ses règles, voir « Modification »).
- La recherche n'est qu'une aide : le contrôle qui fait foi est celui de l'enregistrement (tableau
  ci-dessus), refait côté serveur.
- Import Excel RH : sans effet, l'import ne crée jamais de salarié.
- Aucune migration : la contrainte unique sur le téléphone normalisé existait déjà ; les numéros
  enregistrés sont tous au format international.

## Dossier salarié (27/10/2026)

Tous les champs sont **facultatifs** : les dossiers existants restent valides sans saisie.

| Bloc | Champs | Porté par |
|---|---|---|
| Dossier | Service, date d'embauche (fiche seulement, pas à la création) ; date et motif de sortie en lecture seule | `employes` |
| Identité civile | Date de naissance (antérieure à aujourd'hui), lieu, civilité (Monsieur / Madame / Mademoiselle), nationalité (liste) | `personnes` (partagée avec les autres rôles) |
| Contact d'urgence | Nom, lien (liste + « Autre » précisé), téléphone (un seul contact) — voir ci-dessous | `employes` |
| Données sensibles | N° CNSS **du salarié** | `employes.numero_cnss` |

- **Dates du dossier (31/10/2026, ADR 0045)** : la date d'embauche n'est plus saisie à la création
  de l'employé. Le premier contrat créé la renseigne quand elle est vide (date de début du
  contrat) ; une date déjà connue (ancienneté antérieure, import RH) n'est jamais modifiée. Elle se
  corrige dans la fiche (pas après la date de sortie). Les dates d'un contrat (début, fin prévue) se
  saisissent dans RH → Contrats. La date de sortie et son motif ne passent plus par le formulaire :
  action « Enregistrer le départ » ([rh-depart-salarie.md](rh-depart-salarie.md)). Le statut
  « Sorti » ne se choisit plus dans le formulaire ; un salarié sorti garde ce statut jusqu'à
  l'annulation du départ.
- **Nationalité (31/10/2026)** : liste fermée de nationalités au féminin (« Guinéenne »,
  « Française »…), jamais un nom de pays ; Guinéenne en tête, recherche sans tenir compte des
  accents, drapeau comme repère visuel. Source unique : `App\Support\Rh\Nationalites` (envoyée au
  formulaire, valeur vérifiée par le serveur). Une valeur saisie avant la liste et absente de
  celle-ci reste affichée et acceptée telle quelle tant qu'elle n'est pas remplacée (aucune
  conversion automatique, aucune migration).
- **Lien du contact d'urgence (31/10/2026)** : liste `App\Support\Rh\LiensContactUrgence`
  (Conjoint(e), Père, Mère, Frère, Sœur, Fils, Fille, Oncle, Tante, Cousin(e), Ami(e), Collègue,
  Tuteur / Tutrice, Autre). « Autre » exige une précision (50 caractères), enregistrée à la place
  du libellé dans `contact_urgence_lien` ; choisir un autre lien efface la précision. Une ancienne
  saisie libre se relit comme « Autre » avec cette valeur pour précision. Pas de migration.
- **Téléphone du contact d'urgence (01/11/2026)** : même composant que les autres téléphones
  (drapeau + indicatif visibles), mais le **pays est imposé et non modifiable** : celui du
  téléphone de l'employé (`personnes.code_pays`, issu de son numéro — jamais de sa nationalité) ;
  sans téléphone de l'employé, la Guinée (+224, pays par défaut du sélecteur). Enregistré au
  format international (`+224…`). Le serveur revérifie : numéro valide et du même indicatif que
  l'employé, sinon refus. Une ancienne valeur en saisie libre renvoyée inchangée est conservée
  telle quelle (le dossier reste modifiable) ; pas de migration. Le téléphone est affiché sur sa
  propre ligne, en pleine largeur, sous le nom et le lien.
- **Contact d'urgence déjà connu (01/11/2026, choix « reprise du nom, sans migration »)** : dès
  qu'un numéro complet est saisi, `GET /backoffice/employes/recherche-contact-urgence`
  (`RechercheEmployeParTelephone::proprietairesDuNumero`, droit `rh-employes.create` ou
  `.update`, organisation de l'utilisateur) cherche à qui il appartient déjà : personne (avec ses
  rôles), client, entreprise tierce — même numéro normalisé quel que soit son format. Une seule
  correspondance et nom vide : le nom est repris ; sinon « Reprendre ce nom » sur la bonne
  ligne (plusieurs correspondances : toutes proposées, aucun choix automatique). **Lecture
  seule** : le contact reste enregistré sur la fiche du salarié (nom, lien, téléphone) ; aucune
  fiche personne n'est créée pour lui et aucune fiche existante n'est modifiée. Un même contact
  pour plusieurs salariés = les mêmes valeurs sur chaque fiche, sans doublon de personne. Pas de
  lien vers une fiche personne partagée (écarté : migration, et les proches des salariés
  entreraient dans le référentiel commun).
- **N° CNSS** : numéro personnel d'immatriculation du salarié à la Caisse nationale de sécurité
  sociale (cnss.gov.gn), facultatif, jamais généré par l'application. Le numéro employeur de
  l'entreprise n'est pas géré dans le dossier du salarié.
- **Infobulles ⓘ** : composant partagé `InfoTooltip.vue` (survol, focus clavier, appui sur écran
  tactile) sur les champs ambigus : date d'embauche, nationalité, lien, N° CNSS ; dans les contrats :
  date de début, date de fin, salaire de base, période d'essai ; dans le départ : date et motif.

- À la création, une identité civile déjà renseignée sur la Personne réutilisée est conservée ;
  seuls les champs vides sont complétés (même règle que nom/prénom/email).
- **Civilité (30/10/2026)** : remplace la saisie du sexe (`personnes.civilite`). La colonne
  `personnes.sexe` est conservée et reste alimentée, déduite de la civilité (Monsieur → M, Madame
  et Mademoiselle → F). Reprise des valeurs déjà saisies par la migration
  `2026_10_30_110000_add_civilite_to_personnes_table` : M → Monsieur, F → Madame (Mademoiselle n'est
  jamais déduite, elle se corrige sur la fiche).
- **Mode de paiement du salaire (30/10/2026)** : il n'est plus saisi dans le dossier RH. La
  Comptabilité le choisit au moment du paiement (moyen de paiement de *Paiement salaire*, ADR 0005).
  Les coordonnées déjà enregistrées (`employe_coordonnees_paiement`, numéro **chiffré**) ne sont ni
  modifiées ni supprimées : elles restent consultables en lecture seule sur la fiche employé, avec
  `rh-employes.donnees_sensibles`, sous « Paiement du salaire (ancienne saisie) ». Une valeur
  `paiement_*` envoyée au serveur est ignorée.
- **Services RH** : référentiel par organisation, comme les fonctions RH (libellé et code uniques,
  code proposé depuis le libellé, désactivation au lieu de suppression). Écran *Paramètres → RH →
  Services RH*.

## Permissions

| Permission | Effet | Rôles servis par la migration de rattrapage |
|---|---|---|
| `rh-employes.read` / `.create` / `.update` / `.delete` | Dossier (hors données sensibles et salaires) | inchangé |
| `rh-employes.donnees_sensibles` | Voir et modifier le n° CNSS ; voir les coordonnées de paiement déjà enregistrées | super_admin, admin_entreprise |
| `rh-remunerations.read` | Voir et saisir le salaire (fiche employé, contrats) | super_admin, admin_entreprise, manager |
| `rh-referentiels.gerer` | Gérer les fonctions et services RH, les modèles de contrat et désigner le PDG de l'organisation, signataire des contrats ([rh-contrat-pdf.md](rh-contrat-pdf.md), ADR 0048) | super_admin, admin_entreprise |

- Sans la permission, la donnée n'est **pas envoyée** au navigateur, et une valeur envoyée est
  ignorée (la donnée existante reste intacte).
- `rh-referentiels.gerer` remplace le contrôle codé en dur sur le rôle admin_entreprise (Fonctions RH).
- Aucun accès existant n'est retiré par la migration : un rôle personnalisé qui voyait les salaires
  par `rh-employes.read` doit recevoir `rh-remunerations.read` dans *Rôles*.

## Listes Employés et Contrats

Limitées aux agences de l'utilisateur (agence actuelle de l'employé), sauf vision de toutes les
agences (ADR 0025) ; un employé sans agence n'apparaît qu'à ces derniers. Filtre Agence
(`site_ids[]`), plus filtre Service pour les employés.

**Fiche et contrats (31/10/2026)** : même cloisonnement en accès direct, vérifié par le serveur
(`App\Support\Rh\PerimetreEmploye`, règle unique aussi utilisée par les primes et le départ) :
- consulter la fiche : agences de rattachement, ou vision de toutes les agences ;
- modifier, transférer, supprimer l'employé, créer, modifier ou supprimer ses contrats : agences de
  rattachement, sauf administrateur ou superviseur (ADR 0034) — la vision de toutes les agences
  n'ouvre que la lecture ;
- toujours limité à l'organisation, super administrateur compris.
Les boutons Modifier / Supprimer / Nouveau contrat de la liste et de la fiche suivent la même règle
(`dans_perimetre_ecriture` calculé par le serveur). Le formulaire de création de contrat ne propose
que les salariés actifs des agences de rattachement.

## Modification

- Effacer le téléphone est refusé quand la Personne porte aussi un compte, ou est aussi client,
  livreur, propriétaire, fournisseur, prestataire ou parrain : ce numéro l'identifie partout. Le
  remplacer reste possible.

## Saisie des montants

Le salaire de base (contrats), le montant par défaut d'un type de prime, le montant d'une prime
(attribution, changement, saisie du mois) et le montant d'une retenue utilisent le champ commun
`resources/js/components/MontantInput.vue` : séparateurs de milliers pendant la frappe
(« 750000 » s'affiche « 750 000 »), montant collé avec espaces ou points accepté. Le serveur reçoit
toujours un nombre sans séparateur (null si le champ est vide) ; un texte formaté reste refusé par
la validation. Le salaire n'est plus limité aux multiples de 1 000.

## Effet sur le compte utilisateur existant

Si la Personne réutilisée a déjà un compte et qu'un site est choisi, `EmployeAffectationService`
donne à ce compte l'accès applicatif au site (synchronisation affectation → accès). Le site de
l'affectation devient alors le **seul site par défaut** du compte (les autres agences restent
accessibles, sans être par défaut).

Tests : `tests/Feature/EmployeCreationIdentiteExistanteTest.php`, `tests/Feature/EmployeDossierTest.php`,
`tests/Feature/ServiceRhTest.php`, `tests/Feature/ContratTest.php` (salaire),
`tests/Unit/EmployeAffectationServiceTest.php`.
