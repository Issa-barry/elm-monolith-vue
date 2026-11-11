# ADR 0057 — Standard unique des filtres de période

- **Date** : 2026-11-04
- **Statut** : accepté (demande de l'utilisateur du 04/11/2026)
- **Périmètre** : tous les filtres de période calendaire du back-office ; API espace client et parrain
  dans un lot suivant — cf. [periodes.md](../periodes.md), [audit](../audit-filtres-periodes.md)

## Contexte

L'audit du 04/11/2026 a trouvé sept façons de calculer une période : `SituationPeriode` (fiche véhicule,
rapport d'activité, espace client), le tableau de bord (clés `semaine_derniere`, `mois_dernier`,
trimestres), Ventes et Factures (`today`, `week`, `month`), `ClientEarningsService` (`7j`, `ce_mois`
arrêté à aujourd'hui), deux calculs dans le navigateur (graphique du tableau de bord, export Ventes) et
des dates lues à la main dans dix contrôleurs, sans validation. Le tableau de bord affichait octobre
pour « Mois dernier » les 29, 30 et 31 (`subMonth()` débordait).

## Décision

1. **Un seul moteur** : `SituationPeriode` est généralisé en `App\Support\Periodes\Periode` (même API
   publique), et non doublé. Il porte les clés, les libellés, les bornes, la validation, le filtrage SQL
   et les options pour le front.
2. **Deux modes** : `periode=<clé>` ou `date_debut` + `date_fin` (incluses), exclusifs. Amendement du
   10/11/2026 : `date_fin` est facultative ; vide, la période va jusqu'à aujourd'hui (calculé côté serveur).
3. **Erreur explicite** sur le back-office comme sur l'API (choix de l'utilisateur du 04/11/2026) : plus
   de repli silencieux sur la période par défaut.
4. **Bornes semi-ouvertes** en SQL, sans fonction sur la colonne.
5. **Trimestres et semestres conservés** comme extension du tableau de bord (choix de l'utilisateur).
6. **Compatibilité** : anciennes clés (`today`, `week`, `month`, `all`, `semaine_derniere`,
   `mois_dernier`) et anciens noms de dates (`date_from`, `date_to`) acceptés en entrée.
7. **Libellés** du cahier des charges : « Semaine dernière », « Mois dernier », « Année dernière »,
   « Toutes les périodes ». La clé `tout` reste `tout`.
8. **Périodes métier inchangées** : quinzaines P1/P2, mois comptable, périodes de paie.
9. **Ordre de migration** (choix de l'utilisateur) : back-office d'abord ; l'API espace client et
   parrain ensuite, après le commit du travail ADR 0055 qui touche les mêmes fichiers de contrat.
10. **Protection CI** : registre des écrans testé route par route, test d'architecture sur les
    contrôleurs, règle front dans `check-ui-standards.mjs`.

## Conséquences

- Factures : le défaut `month` devient `ce_mois` (mêmes bornes).
- Export Ventes : le modal n'envoie plus de dates calculées par le navigateur ; « 3 derniers mois » et
  « 6 derniers mois » sont retirés (remplacés par la période personnalisée).
- Une seule date (ancien « depuis le… ») est désormais refusée sur Ventes, Logistique, Dépenses, Cashback,
  Communications, Historique, Propositions et les écrans de contrôle.
- Achats, Factures fournisseurs et Mouvements de fonds gagnent le filtre « Période ».
- Les écrans de contrôle (encaissements, commissions) passent de `detection_debut`/`detection_fin` à
  `date_debut`/`date_fin` (ces écrans n'ont pas de vues enregistrées).
