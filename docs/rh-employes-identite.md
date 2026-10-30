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
| Pas de téléphone saisi | Nouvelle Personne systématiquement (aucun rapprochement possible). |
| Téléphone d'un utilisateur, client, propriétaire, livreur… | La Personne existante est réutilisée : un seul individu, plusieurs rôles. |
| Personne réutilisée sans nom/prénom/email (livreur créé au seul téléphone, client avec `nom_complet` seul) | Les champs **vides** sont complétés avec la saisie RH. |
| Personne réutilisée avec un nom/prénom/email déjà renseigné et différent | L'identité existante est conservée ; la saisie RH est ignorée pour ces champs. |
| Personne déjà employée (fiche non supprimée) | Refus : « Cette personne est déjà enregistrée comme employé (…, matricule …) ». |
| Ancien employé supprimé (soft delete) | Réembauche autorisée : nouvelle fiche, nouveau matricule. |
| Email déjà porté par un autre employé de l'organisation | Refus (comparaison insensible à la casse). |
| Site ou fonction d'une autre organisation | Refus (`site_id`, `fonction_rh_id`), en création comme en modification. |

La création est transactionnelle : la Personne est verrouillée le temps du contrôle « déjà
employé », deux créations simultanées avec le même téléphone ne peuvent pas aboutir toutes les deux.

## Dossier salarié (27/10/2026)

Tous les champs sont **facultatifs** : les dossiers existants restent valides sans saisie.

| Bloc | Champs | Porté par |
|---|---|---|
| Dossier | Service, date d'embauche, date de sortie (≥ embauche), motif de sortie | `employes` |
| Identité civile | Date de naissance (antérieure à aujourd'hui), lieu, civilité (Monsieur / Madame / Mademoiselle), nationalité | `personnes` (partagée avec les autres rôles) |
| Contact d'urgence | Nom, lien, téléphone (un seul contact) | `employes` |
| Données sensibles | N° CNSS | `employes.numero_cnss` |

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
| `rh-referentiels.gerer` | Gérer les fonctions et services RH, et les modèles de contrat ([rh-contrat-pdf.md](rh-contrat-pdf.md)) | super_admin, admin_entreprise |

- Sans la permission, la donnée n'est **pas envoyée** au navigateur, et une valeur envoyée est
  ignorée (la donnée existante reste intacte).
- `rh-referentiels.gerer` remplace le contrôle codé en dur sur le rôle admin_entreprise (Fonctions RH).
- Aucun accès existant n'est retiré par la migration : un rôle personnalisé qui voyait les salaires
  par `rh-employes.read` doit recevoir `rh-remunerations.read` dans *Rôles*.

## Listes Employés et Contrats

Limitées aux agences de l'utilisateur (agence actuelle de l'employé), sauf vision de toutes les
agences (ADR 0025) ; un employé sans agence n'apparaît qu'à ces derniers. Filtre Agence
(`site_ids[]`), plus filtre Service pour les employés.

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
