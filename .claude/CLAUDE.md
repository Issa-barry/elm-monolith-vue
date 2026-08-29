# CLAUDE.md — Eau La Maman (ELM)

Ce fichier contient les règles permanentes à respecter pour toute intervention sur ce projet.

## 1. Autonomie d'exécution

Quand une demande est suffisamment claire, exécute le chantier de bout en bout sans demander de confirmation intermédiaire.

Enchaîne automatiquement lorsque nécessaire :
- audit du code existant ;
- lecture des règles métier concernées ;
- modification backend ;
- modification frontend ;
- migrations non destructives nécessaires ;
- tests ;
- correction des régressions directement causées par le changement ;
- lint ;
- typecheck ;
- Pint ;
- build ;
- mise à jour de la documentation concernée.

Ne demande pas :
- « Veux-tu que je continue ? »
- « Souhaites-tu que je fasse la correction ? »
- « Veux-tu que j'ajoute les tests ? »
- « Veux-tu que je mette à jour la documentation ? »
- « Dois-je corriger cette incohérence ? »

Si cela fait clairement partie du chantier demandé, fais-le directement.

## 2. Quand demander confirmation

Demande confirmation uniquement lorsqu'une véritable décision utilisateur est nécessaire, notamment :

- règle métier ambiguë avec plusieurs comportements possibles ;
- suppression ou perte potentielle de données ;
- migration destructive ;
- modification importante de données de production ;
- action directe en production ;
- opération irréversible ;
- choix fonctionnel impossible à déduire du code, des tests ou de la documentation.

Ne transforme pas une simple décision technique d'implémentation en question utilisateur si une solution sûre et cohérente avec l'architecture existante peut être déterminée.

## 3. Git

Ne jamais effectuer automatiquement :
- commit ;
- push ;
- merge ;
- rebase destructif ;
- suppression de branche ;
- modification de l'historique Git.

Ces opérations nécessitent une demande explicite de l'utilisateur.

Il est permis d'utiliser les commandes Git en lecture seule pour comprendre l'état du projet :
- git status ;
- git diff ;
- git log ;
- git show ;
- git branch.

À la fin d'un chantier, indiquer simplement si le projet est prêt à être commité.

## 4. Respect de l'existant

Avant de modifier une fonctionnalité :
1. inspecter l'implémentation existante ;
2. rechercher les services/composants déjà utilisés ;
3. rechercher les tests existants ;
4. consulter la documentation métier correspondante ;
5. vérifier les impacts Web, API, PDV, import/export et autres interfaces concernées.

Ne pas créer une deuxième implémentation lorsqu'un composant ou service partagé existe déjà.

Privilégier la réutilisation et la cohérence avec l'architecture existante.

Ne pas effectuer de refactoring sans rapport avec le chantier demandé.

## 5. Règles métier

Une règle métier existante ne doit jamais être modifiée silencieusement.

Avant toute modification métier :
- identifier la règle actuelle ;
- vérifier les tests associés ;
- vérifier la documentation ;
- identifier les impacts potentiels.

Si le besoin demandé entre en contradiction avec une règle documentée, signaler explicitement la contradiction avant de remplacer la règle.

Le code, les tests et la documentation doivent décrire le même comportement.

## 6. Documentation obligatoire

Toute modification affectant une règle métier doit mettre à jour la documentation correspondante dans `/docs`.

Cela concerne notamment :
- clients ;
- produits ;
- tarification ;
- ventes ;
- commandes ;
- facturation ;
- encaissement ;
- solvabilité ;
- impayés ;
- dérogations ;
- cashback ;
- commissions ;
- stock ;
- logistique ;
- dépenses ;
- comptabilité ;
- permissions.

Après une modification métier :
1. modifier le code ;
2. modifier ou ajouter les tests ;
3. mettre à jour la documentation ;
4. créer ou mettre à jour un ADR si une décision structurante est prise.

Ne demande pas séparément l'autorisation de mettre à jour la documentation lorsqu'elle fait partie du changement demandé.

## 7. Tests

Toute correction de bug doit, lorsque raisonnablement possible, être accompagnée d'un test empêchant sa réapparition.

Tester d'abord le périmètre concerné, puis exécuter les régressions pertinentes.

Ne pas supprimer, désactiver ou assouplir un test simplement pour faire passer une implémentation.

Si un test existant devient incorrect à cause d'une nouvelle règle métier explicitement demandée, le mettre à jour et expliquer pourquoi.

## 8. Bugs découverts pendant un chantier

Si un bug directement lié au chantier empêche le fonctionnement demandé :
→ le corriger directement ;
→ ajouter/adapter les tests ;
→ le mentionner dans le rapport final.

Si un problème est sans rapport avec le chantier :
→ ne pas modifier le code concerné ;
→ le signaler dans le rapport final comme point hors périmètre.

Éviter l'élargissement incontrôlé du périmètre.

## 9. Backend comme source de vérité

Les règles métier sensibles doivent être garanties côté backend.

Le frontend ne doit pas être la seule protection pour :
- tarification ;
- solvabilité ;
- autorisations ;
- dette ;
- commissions ;
- cashback ;
- stock ;
- validation financière.

Le frontend représente l'état métier retourné par le backend et améliore l'expérience utilisateur.

Ne jamais faire confiance à un montant ou à une règle métier sensible provenant uniquement du frontend.

## 10. UI / UX

Respecter une sémantique cohérente dans toute l'application :

- SUCCESS / vert = situation normale ou réussite ;
- WARNING / orange-ambre = attention, mais opération encore autorisée ;
- DANGER / rouge = erreur ou opération réellement bloquée ;
- INFO / bleu = information sans anomalie.

Ne pas afficher une situation autorisée en rouge simplement parce qu'une anomalie existe.

Exemple :
impayé + dérogation valide + commande autorisée
→ WARNING, pas DANGER.

Réutiliser les composants partagés et les styles existants avant d'en créer de nouveaux.

## 11. Sécurité et multi-tenant

Pour toute fonctionnalité manipulant des données métier :
- respecter `organization_id` ;
- empêcher les accès cross-organization ;
- conserver les Policies/middlewares/autorisations existants ;
- ne jamais contourner une protection pour simplifier une implémentation.

Ajouter des tests d'isolation organisationnelle lorsqu'un nouveau point d'entrée sensible est créé.

## 12. Migrations et données

Ne jamais effectuer de migration destructive ou de transformation risquée des données sans confirmation.

Pour une migration normale :
- préserver les données existantes ;
- prévoir les valeurs historiques nécessaires ;
- vérifier les contraintes ;
- éviter les changements silencieux de comportement.

Une valeur initialisée pendant une migration ne doit pas devenir implicitement une règle métier permanente.

## 13. Commentaires et terminologie

Utiliser la terminologie métier actuelle.

Lorsqu'une ancienne terminologie est rencontrée dans un commentaire ou docblock directement lié au chantier, la corriger.

Ne pas renommer un concept homonyme appartenant à un autre domaine métier sans vérifier sa signification.

Les commentaires doivent décrire le fonctionnement actuel et non une ancienne implémentation.

## 14. Fin de chantier

Ne termine pas par :
« Dis-moi si tu veux que je continue ».

Termine par un rapport factuel contenant :

### Modifications
- fichiers/fonctionnalités modifiés ;
- comportement final.

### Vérifications
- tests exécutés ;
- résultat ;
- lint/typecheck/build si concernés.

### Documentation
- documentation mise à jour ;
- ADR créé/modifié si nécessaire.

### Points hors périmètre
- éventuels problèmes découverts mais volontairement non modifiés.

### Verdict
- prêt à commit ;
ou
- non prêt à commit avec raison précise.

Ne commit rien sauf demande explicite.