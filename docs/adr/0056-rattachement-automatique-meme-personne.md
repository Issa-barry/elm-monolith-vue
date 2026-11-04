# ADR 0056 — Rattachement automatique des activités d'une personne à son compte

- **Date** : 2026-11-04
- **Statut** : accepté (demande et décision de l'utilisateur du 04/11/2026)
- **Révise** : [ADR 0050](0050-rattachement-manuel-comptes-parrain-consultant.md), décisions 2
  (« rattachement manuel uniquement ») et 6, et l'option écartée « déduire le lien de `users.personne_id` »
- **Périmètre** : `elm-monolithe` (rattachement des fiches livreur, propriétaire, parrain, consultant et
  interlocuteur) — cf. [rattachement-comptes.md](../rattachement-comptes.md)

## Contexte

Cas réel du 04/11/2026 : Fodé SAA a un compte, est livreur du véhicule Abarry et parrain du véhicule ADAMA
(OU114). Sa fiche parrain pointe vers la même fiche Personne que son compte et que sa fiche livreur, mais
elle n'a pas de `user_id` : l'ADR 0050 imposait un rattachement manuel. Sur Nuxt, il ne voyait que son
activité de livreur. La base locale montre le même cas pour un autre parrain.

Une personne est enregistrée une seule fois (`Personne::resoudreOuCreer()`, unique par organisation et
téléphone) : quand un responsable crée la fiche parrain d'un livreur, la fiche reprend sa Personne.
Redemander un rattachement manuel pour une identité que le système connaît déjà n'apporte aucune
vérification.

## Décision

1. **Identité établie = même fiche Personne.** Une fiche sans compte est reliée automatiquement au compte
   dont l'identité recouvre sa Personne : la fiche Personne du compte (`users.personne_id`), ou celle d'une
   autre fiche déjà reliée au compte dans l'organisation (livreur, propriétaire, parrain, consultant,
   interlocuteur). Choix de l'utilisateur parmi trois options (même Personne, même Personne sauf
   entreprises, lien posé par un humain seulement).
2. **Jamais sur un téléphone seul.** Un numéro identique sans Personne commune ne relie rien : le cas reste
   au rattachement manuel, comme avant.
3. **Cas laissés au manuel** : plusieurs comptes pour la même Personne, compte désactivé ou d'une autre
   organisation, refus de l'ADR 0050 (compte déjà parrain, déjà consultant, déjà interlocuteur de
   l'entreprise, fiche désactivée...), compte déjà relié à une autre fiche livreur ou propriétaire, et
   compte qu'un responsable a détaché de cette fiche. Un détachement n'est jamais annulé automatiquement.
4. **Dans les deux sens, au fil de l'eau** (`RattachementAutomatiqueObserver`, après validation de la
   transaction) : fiche créée ou dont la Personne change, compte qui reçoit une fiche Personne (inscription,
   compte créé au back-office), activité nouvellement reliée dont la Personne porte d'autres fiches. Un échec
   n'annule jamais l'opération d'origine ; la fiche reste au manuel.
5. **Fiches concernées** : livreur, propriétaire (y compris entreprise, par son contact), parrain,
   consultant personne physique, interlocuteur d'entreprise. Une entreprise consultante n'est jamais reliée
   directement ; ses interlocuteurs le sont, et les commissions restent celles de l'entreprise.
6. **Traçabilité** : chaque rattachement automatique écrit `compte_rattache` dans `audit_logs`, sans auteur,
   avec `meta.automatique`, `meta.source` (`fiche`, `compte`, `activite_reliee`, `regularisation`) et
   `meta.preuve`. La fiche parrain l'affiche comme « Rattachement automatique ».
7. **Régularisation de l'existant** : commande `comptes:regulariser-rattachements`, en simulation par
   défaut. Bilan par activité, détail nominatif (`--csv`), puis `--apply` qui ne relie que les
   correspondances certaines, chacune re-vérifiée dans sa transaction. Relancée, elle ne refait rien.
8. **Le manuel devient une correction exceptionnelle** : le bouton « Rattacher manuellement » reste au
   back-office, secondaire, pour les administrateurs habilités (`rattachements-comptes.gerer`) et les cas
   laissés au manuel. La fiche sans compte affiche pourquoi le rattachement automatique n'a pas eu lieu.
9. **Inchangé** : inscription, connexion, OTP, rattachement par téléphone des livreurs et propriétaires à
   la connexion, rattachement et détachement manuels, moteur de commissions, calculs et paiements. Aucune
   fiche, Personne ou compte n'est créé, fusionné ou supprimé.

## Risque accepté

Le lien entre un compte auto-inscrit et sa Personne vient de l'inscription : le numéro saisi correspondait
à celui d'une fiche livreur ou propriétaire (`RegistrationService`), et ce numéro n'est pas vérifié (aucun
`verified_at` posé, l'OTP n'en pose pas). Quelqu'un qui s'inscrit avec le numéro d'un autre obtient déjà ses
commissions de livreur ; avec cette décision, il obtient aussi ses commissions de parrain, de consultant ou
d'interlocuteur. Les protections restent la trace de chaque rattachement et le détachement par un
responsable, qui bloque tout rattachement automatique ultérieur de la même fiche à ce compte. La faille
d'inscription reste documentée à part.

Risque réexaminé et maintenu par l'utilisateur le 04/11/2026, après l'ouverture du front aux agents
([ADR 0055](0055-compte-unique-back-office-espace-client.md)). Retirer le seul rattachement à la connexion ne
le supprimerait pas : une Personne étant unique par organisation et téléphone, la fiche créée avec le numéro
d'un agent porte déjà sa Personne, et l'inscription relie elle aussi par numéro. Seule une preuve de possession
du numéro le fermerait, dans un chantier dédié. Le rattachement à la connexion reste limité à l'organisation
du compte quand elle est connue.

## Limite connue

Une personne qui n'est que parrain, consultant ou interlocuteur et qui s'inscrit ensuite n'est pas reliée
automatiquement : l'inscription lui crée une fiche Personne neuve (seules les fiches livreur et propriétaire
sont reprises par le numéro), et le seul lien restant est un numéro non vérifié. La fiche reste au
rattachement manuel. Pour l'automatiser, il faudrait une preuve de possession du numéro (code OTP qui
pose `verified_at`) : hors périmètre, l'inscription et l'OTP ne changent pas.

## Options écartées

- **Lien posé par un humain seulement** (rattachement manuel, compte créé au back-office, téléphone
  vérifié) : aucun cas réel n'aurait été relié, le téléphone n'étant jamais vérifié aujourd'hui.
- **Même Personne sauf interlocuteurs d'entreprise** : écartée par l'utilisateur, la même règle vaut pour
  toutes les activités.
- **Résolution dynamique à chaque requête** (sans écrire `user_id`) : un détachement serait aussitôt annulé,
  et rien ne serait tracé.
