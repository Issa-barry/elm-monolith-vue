# ADR 0013 — Monitoring des commissions non générées : une vue dérivée, pas un second registre

- **Date** : 2026-09-30
- **Statut** : accepté, livré le 2026-09-30
- **Périmètre** : génération des commissions (vente, distribution, transfert logistique), alerte
  « Commission non générée », écran Commissions → Monitoring — cf.
  [commissions.md](../commissions.md) (COMM-020)

## Contexte

En production, des commissions attendues ne sont pas générées. La cause la plus fréquente est un
partage Livreur non conforme au barème, par exemple « Dépassement de 150 GNF sur l'enveloppe
Livreur de 800 GNF (attribué : 950 GNF) ». La seule trace visible était un email par commande,
envoyé aux administrateurs. Certains de ces emails n'étaient même pas distribués (adresse
destinataire invalide). Il n'existait aucune liste, aucun suivi de la régularisation et aucune
relance groupée.

Or le moteur enregistre déjà chaque tentative de génération dans `commission_generation_attempts` :
table append-only, statut SUCCES/PARTIEL/ERREUR, motif. Le statut « à régulariser » de la fiche
commande est calculé depuis cette table (décision existante : jamais un champ stocké à part).

## Décision

1. **Pas de table d'anomalies.** Le monitoring est une vue calculée sur
   `commission_generation_attempts` et les enveloppes existantes (`CommissionMonitoringService`).
   Une anomalie = une opération, un processus et une cible manquante, au grain d'une enveloppe du
   moteur. Son statut (non générée, échec récurrent, régularisée, sans objet) est dérivé, jamais
   stocké.
2. **Motif structuré à la source.** Le moteur enregistre, pour chaque cible en échec, un code de
   motif, le montant attendu et le contexte de diagnostic dans `detail_erreur.cibles[]`. Le texte de
   `motif_erreur` est inchangé. L'historique, qui n'a que le texte, est classé à la lecture.
3. **Pas de second moteur.** La relance appelle `CommissionEnveloppeGenerator`, avec son verrou,
   son idempotence et la complétion des seules cibles manquantes (COMM-018). Une relance multiple
   traite chaque opération isolément.
4. **« Aucune commission due » n'est pas une anomalie** : pas de barème, barème à 0, site désactivé,
   cible non applicable.
5. La relance exige `commissions.update` ; la lecture suit `canReadCommissions()`.

## Options écartées

- **Table `commission_anomalies` avec statut stocké** (OUVERTE / EN_COURS / RÉGULARISÉE…) : elle
  dupliquerait l'état déjà porté par les tentatives et les enveloppes. Elle pourrait diverger (une
  relance depuis la fiche commande ou un retour de livraison devrait la tenir à jour), exigerait une
  reprise des données historiques et contredirait la règle « statut de génération dérivé, jamais
  stocké ».
- **Statut « en cours »** : la relance est synchrone sous verrou de l'opération, donc il n'existe
  aucun état intermédiaire observable.
- **Relance d'un échec total à la date d'origine** : cette règle a été tranchée par l'ADR 0006
  (date du jour) ; ce chantier ne la modifie pas.

## Conséquences

- Les anomalies de production antérieures apparaissent immédiatement, sans migration.
- Le calcul se fait à la lecture, sur les seules opérations ayant au moins une tentative en échec :
  quelques requêtes groupées, quel que soit le nombre d'anomalies. Si ce volume devenait important,
  une matérialisation pourrait être envisagée plus tard, sans changer les règles.
- Une opération dont la génération n'a jamais été déclenchée reste hors de l'écran : elle est
  couverte par `commissions:auditer-ventes`.
