# ADR 0014 — Unicité des références Mobile Money

- **Date** : 2026-10-01
- **Statut** : accepté, livré le 2026-10-01
- **Périmètre** : encaissements de vente en Mobile Money (`encaissements_ventes`), contrôle des
  références du rapport d'activité — cf. [encaissements.md](../encaissements.md) et
  [rapports.md](../rapports.md) (RAP-007)

## Contexte

La référence de transaction est obligatoire pour un encaissement Mobile Money depuis le 14/09/2026,
mais rien n'empêchait de la réutiliser : une même preuve de paiement pouvait justifier plusieurs
encaissements. Le rapport d'activité signalait seulement après coup les doublons, et seulement pour
un même opérateur.

## Décision

1. **Une référence Mobile Money ne sert qu'une fois par organisation**, quels que soient la vente,
   l'agence, l'agent ou l'opérateur. L'opérateur ne distingue pas deux références : la règle porte
   sur le code, pas sur le couple (opérateur, code). Le rapport d'activité applique désormais la
   même définition du doublon.
2. **Par organisation, pas globale** : une unicité entre organisations laisserait une organisation
   découvrir qu'une référence existe chez une autre (règle d'isolation multi-organisation).
3. **Normalisation** : espaces de bord et casse ignorés ; la référence d'un nouvel encaissement
   Mobile Money est stockée normalisée.
4. **Deux niveaux** : contrôle applicatif (message « Référence déjà utilisée — facture VTE-… »,
   numéro copiable dans PaymentCard, pour retrouver immédiatement l'autre encaissement) et index unique sur une colonne technique `cle_reference_mobile_money`
   (`organisation|RÉFÉRENCE`), calculée par le modèle pour tout appelant. L'index tranche entre deux
   saisies simultanées ; le contrôleur convertit ce refus en même message.
5. **Doublons historiques conservés** : la migration ne modifie ni ne supprime aucun encaissement.
   Le plus ancien de chaque groupe reçoit la clé, ce qui bloque toute nouvelle utilisation ; les
   suivants restent sans clé, signalés par le rapport et listés par
   `encaissements:doublons-reference-mobile-money`. Le déploiement ne dépend donc pas d'un nettoyage
   préalable.
6. **Autres modes non concernés** : Espèces (pas de référence), Virement et Chèque gardent leurs
   règles actuelles.

## Conséquences

- Un encaissement supprimé (suppression, annulation exceptionnelle) libère sa référence.
- Le message nomme la facture même hors du périmètre d'agences de l'utilisateur, contrairement au
  rapport d'activité (ADR 0007) qui n'en détaille que les utilisations visibles : à la saisie, le
  numéro est nécessaire pour lever le doublon, et il ne sort pas de l'organisation.
- Si l'encaissement qui porte la clé d'un groupe historique est supprimé, les doublons restants
  n'en portent pas : la référence redevient saisissable. Cas limité à l'historique, accepté.
- La régularisation des doublons historiques reste une décision humaine, hors de cet ADR.
