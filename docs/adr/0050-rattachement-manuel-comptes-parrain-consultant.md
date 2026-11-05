# ADR 0050 — Relier un compte à un parrain ou à un consultant : rattachement manuel, sans toucher à l'authentification

- **Date** : 2026-11-02
- **Statut** : accepté (décisions de l'utilisateur du 02/11/2026) ; décision 7 révisée par
  l'[ADR 0055](0055-compte-unique-back-office-espace-client.md) le 04/11/2026 ; décisions 2 et 6 et option
  « déduire le lien de `users.personne_id` » révisées par l'[ADR 0056](0056-rattachement-automatique-meme-personne.md)
  le 04/11/2026 (rattachement automatique quand la fiche partage la Personne du compte, et sur le numéro du compte pour un parrain)
- **Périmètre** : parrains, consultants, interlocuteurs d'entreprise, futurs espaces Nuxt — cf.
  [rattachement-comptes.md](../rattachement-comptes.md), [parrainage-vehicule.md](../parrainage-vehicule.md),
  [identite-client-personne.md](../identite-client-personne.md)

## Contexte

Un parrain ou un consultant peut créer un compte, mais ce compte n'est relié ni à sa fiche ni à ses
commissions : `parrains` et `prestataires` n'avaient pas de `user_id`, et ni l'inscription ni
`ClientIdentityResolver` ne les connaissent. Un consultant peut en outre être une entreprise tierce,
sans personne physique, et cette entreprise doit pouvoir être représentée par plusieurs personnes.

L'inscription ne prouve pas la possession du numéro de téléphone. Le rattachement automatique par
téléphone utilisé pour les livreurs et les propriétaires ne peut donc pas donner accès aux commissions
d'un parrain ou d'un consultant sans risque d'attribution au mauvais compte.

## Décision

1. **L'authentification ne change pas** : ni l'inscription, ni la connexion, ni le code OTP, ni la
   reconnaissance actuelle des livreurs, propriétaires et clients, ni les applications mobiles.
2. **Rattachement manuel uniquement** : un responsable relie explicitement un compte à une fiche depuis
   le back-office (`RattachementCompteService`, permission `rattachements-comptes.gerer`). Un numéro
   identique sert à proposer un compte, jamais à le relier. Avant de confirmer, le responsable voit le
   nom saisi, le numéro, la date d'inscription et les rôles du compte.
3. **Rattachement refusé** : compte désactivé ou non activé, compte d'une autre organisation, fiche désactivée ou déjà reliée à un autre compte, compte déjà relié à une
   autre fiche parrain ou à un autre consultant, ou déjà interlocuteur de la même entreprise. Le cumul
   d'activités différentes reste permis, comme la représentation de plusieurs entreprises.
4. **Données** : `parrains.user_id` et `prestataires.user_id` (personne physique), et une nouvelle table
   `entreprise_interlocuteurs`, justifiée par la relation de plusieurs à plusieurs entre entreprises et
   personnes. `entreprises_tierces.contact_personne_id` n'admet qu'une personne et n'est pas utilisé.
   `user_id` n'est pas mass-assignable.
5. **Un interlocuteur n'est jamais bénéficiaire** : les parts et les fiches restent attribuées au
   prestataire de l'entreprise. L'accès demande une autorisation explicite (`acces_commissions`, un seul
   droit : consulter les commissions et paiements de l'entreprise).
6. **Accès calculé à chaque requête** (`ActivitesCompteResolver`), depuis `user_id` uniquement, jamais
   depuis le téléphone : désactiver ou détacher coupe l'accès immédiatement, sans supprimer le compte.
7. **Aucun rôle Spatie ajouté** : les données décident de l'accès. *Révisé par l'ADR 0055 (04/11/2026)* :
   le compte d'un agent du back-office, sans rôle `client`, se rattache comme les autres ; le
   rattachement lui donne les données de la fiche dans l'espace client (ouvert à tout compte actif),
   sans toucher à ses rôles.
8. **Aucune fusion ni suppression** : le compte garde sa fiche `Personne`, même si elle fait doublon avec
   celle du parrain. Le rattachement renseigne seulement l'organisation du compte si elle est vide ; le
   détachement ne la retire pas.
9. **Aucun rattachement en masse**, ni à la migration ni par commande.
10. **Traçabilité** : chaque rattachement et détachement est écrit dans `audit_logs`
    (`compte_rattache`, `compte_detache`).

Un parrain ou un consultant désactivé ou remplacé garde l'accès à son propre historique : les données se
lisent par bénéficiaire et ses remplaçants ont leurs propres parts.

## Conséquences

- Le responsable doit réellement contrôler le compte avant de confirmer : le numéro n'étant pas vérifié à
  l'inscription, le compte proposé peut appartenir à quelqu'un d'autre. Ce contrôle humain est la
  protection des commissions.
- Un parrain ou un consultant n'a accès à rien entre son inscription et le rattachement.
- ~~Le compte d'un membre du staff ne peut pas être rattaché tant qu'il n'a pas accès à l'espace client.~~
  Remplacé par l'ADR 0055 : un compte unique, rattachable quel que soit son rôle back-office.
- Aucune règle de calcul, de validation ou de paiement des commissions ne change.

## Options écartées

- **Rattachement automatique par téléphone**, comme pour les livreurs : expose les commissions à quiconque
  s'inscrit avec le numéro.
- **Preuve du numéro par SMS à l'inscription et invitations** : écartées par l'utilisateur, l'inscription
  actuelle est conservée.
- **Un seul compte par entreprise** via `contact_personne_id` : ne couvre pas plusieurs interlocuteurs.
- **Déduire le lien de `users.personne_id`** : impossible pour une entreprise, et la fiche d'un compte
  auto-inscrit est distincte de celle du parrain.
