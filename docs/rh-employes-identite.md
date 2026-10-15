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

## Effet sur le compte utilisateur existant

Si la Personne réutilisée a déjà un compte et qu'un site est choisi, `EmployeAffectationService`
donne à ce compte l'accès applicatif au site (synchronisation affectation → accès).

Tests : `tests/Feature/EmployeCreationIdentiteExistanteTest.php`.
