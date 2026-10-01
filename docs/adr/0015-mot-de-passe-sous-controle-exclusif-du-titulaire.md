# ADR 0015 — Le mot de passe reste sous le contrôle exclusif de son titulaire

- **Date** : 2026-10-01
- **Statut** : accepté, livré le 2026-10-01
- **Périmètre** : comptes back-office (`User`) — fiche agent (docs/fiche-agent.md), formulaire
  « Modifier le compte » (`users.edit` / `users.update`)

## Contexte

Un administrateur pouvait choisir un nouveau mot de passe pour un autre compte de deux façons :

- l'onglet **Mot de passe** du formulaire « Modifier le compte » (`PUT users/{user}/password`,
  `UpdatePasswordUserController`), repris un temps sur la fiche agent ;
- un champ `password` facultatif accepté par `users.update`, sans être affiché à l'écran.

Celui qui fixe le mot de passe d'un autre peut ensuite se connecter à sa place. Les actions
enregistrées sous le nom de l'agent n'en sont alors plus une preuve : ventes, encaissements,
dépenses, versements de caisse.

## Décision

1. **Personne ne définit ni ne réinitialise directement le mot de passe d'un autre utilisateur**,
   quel que soit son rôle, super administrateur compris.
2. Un utilisateur change **son propre** mot de passe dans **Paramètres → Mot de passe** (et dans
   l'application mobile pour son compte). Ces deux chemins sont inchangés.
3. En cas d'oubli, la réinitialisation passe **uniquement** par le lien de réinitialisation envoyé
   au titulaire (Fortify `ResetUserPassword`).
4. La règle est garantie côté serveur :
   - la route `users.update-password` et `UpdatePasswordUserController` sont supprimées ;
   - `users.update` ne valide plus et n'enregistre plus aucun champ `password` (s'il est envoyé,
     il est ignoré).
5. L'interface ne propose plus d'onglet Mot de passe, ni sur la fiche agent, ni dans « Modifier le
   compte ».

## Hors périmètre (inchangé)

- **Création d'un compte** (`users.store`) : le créateur saisit toujours un mot de passe initial.
  Le choix d'imposer plutôt une invitation (`UserInvitationService`, où l'agent choisit lui-même
  son mot de passe), ou un changement obligatoire à la première connexion, reste à trancher.
- Les espaces client / livreur / propriétaire gardent leurs propres parcours (OTP, inscription).

## Conséquences

- Un agent qui a oublié son mot de passe doit avoir un e-mail ou un téléphone valide pour recevoir
  le lien. Un administrateur ne peut plus le « dépanner » en lui fixant un mot de passe.
- Tests : `UserControllerTest` (route absente, champ ignoré), `FicheAgentTest`, E2E `user-flow`
  (aucun champ mot de passe sur l'édition), `Users/__tests__/Show.spec.ts`.
