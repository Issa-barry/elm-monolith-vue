# ADR 0053 — Filtre période comptable P1/P2 de l'espace client

- **Date** : 2026-11-03
- **Statut** : accepté (demande de l'utilisateur du 03/11/2026)
- **Périmètre** : `GET /v1/mobile/dashboard`, `GET /v1/mobile/activite`,
  `GET /v1/mobile/depenses/mine`, écrans Accueil, Commissions, Ventes (Activité) et Dépenses de l'espace
  client Nuxt — cf. [filtre-periode-comptable.md](../filtre-periode-comptable.md)

## Contexte

Les commissions et les salaires se suivent et se paient par quinzaine : P1 du 1er au 15, P2 du 16 à la
fin du mois. L'espace client ne permettait pas de consulter une quinzaine : chaque écran avait ses
propres périodes (7 jours, ce mois, dates libres…), certaines calculées dans le navigateur.

## Décision

1. **Un filtre commun, calculé par le serveur.** `annee` + `mois` + `quinzaine` (`P1`, `P2`, `TOUT`).
   Les bornes viennent de `PeriodeComptableService::dateRangeForCode`, déjà utilisé par le back-office.
   Le calendrier est fixe : un paiement en retard ne déplace aucune opération.
2. **Le paramètre s'appelle `quinzaine`, pas `periode`.** `periode` désigne déjà les périodes rapides de
   `GET /v1/mobile/activite`, déployées dans le commit `f154a770`. Réutiliser ce nom aurait mêlé deux
   vocabulaires sur un même paramètre.
3. **Chaque module garde sa date.** Ventes : `validated_at`, sinon `created_at` ; transferts :
   `date_depart_reelle`, sinon `created_at` ; dépenses : `date_depense`.
4. **Les commissions suivent la date de gain.** Les parts de vente l'utilisaient déjà. Pour les parts
   logistiques, le dashboard filtrait sur `created_at` alors que les fiches de paiement utilisent
   `earned_at` : le filtre comptable utilise `earned_at`, afin qu'une quinzaine regroupe les mêmes
   commissions que la fiche. Les autres filtres du dashboard et l'espace client Inertia restent sur
   `created_at` : les modifier aurait changé des montants déjà affichés, sans que cela soit demandé.
5. **Filtre exclusif.** Combiné à un autre filtre de date, il donne un 422 plutôt qu'un choix silencieux.
   Les filtres existants de l'interface restent disponibles : choisir l'un désactive l'autre.
6. **Défaut porté par l'écran, pas par l'API.** Les écrans s'ouvrent sur le mois en cours ; une requête
   sans ces paramètres se comporte comme avant, ce qui préserve les autres clients de l'API.
7. **L'accueil suit un seul filtre global** (ajout du 03/11/2026). Le dashboard accepte aussi `periode`
   (périodes rapides de `SituationPeriode`, plus `tout`), et `annee` sans `mois` couvre l'année entière.
   « Ce mois » est le mois comptable en cours : l'accueil et les autres écrans s'ouvrent ainsi sur la
   même période. Ces choix datent aussi la logistique par `earned_at`, sinon « Ce mois » et « Novembre »
   pourraient différer sur le même écran.

## Conséquences

- Une vente et sa commission peuvent apparaître dans deux périodes différentes si la validation et le
  gain tombent de part et d'autre d'une borne : c'est le prix de la décision 3.
- Une part logistique peut changer de mois entre le filtre comptable et les anciens choix de la liste
  « Période » de Commissions, restés sur `created_at`, quand son gain et sa création tombent dans deux
  mois différents.
- La carte « Dépenses » de l'accueil mobile ne montre plus un total toutes périodes (correctif du matin
  du 03/11/2026) mais le total de la période choisie. Une dépense de septembre affiche donc 0 GNF sur
  « Ce mois », avec cette période écrite juste au-dessus, et son montant sur « Toutes les périodes ».
- Les écrans Ventes et Dépenses n'affichent plus tout l'historique à l'ouverture.

## Alternatives écartées

- **Code unique `periode_comptable=2026-11-P1`** (format du back-office) : plus court, mais une seule
  erreur de validation pour trois informations distinctes, et moins lisible pour l'interface.
- **Passer tout le dashboard sur `earned_at`** : plus cohérent, mais modifie les montants des filtres
  existants et de l'espace client Inertia.
