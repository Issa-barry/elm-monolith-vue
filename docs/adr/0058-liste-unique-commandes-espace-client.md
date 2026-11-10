# ADR 0058 — Liste unique « Commandes » de l'espace client

- **Date** : 2026-11-06 (décisions validées par l'utilisateur le 05/11/2026, réalisation reportée après
  les lots de sécurité 0A, 0B, la stabilisation et les écritures livreur, reprise le 06/11/2026)
- **Statut** : accepté
- **Périmètre** : `GET /v1/mobile/activite`, `GET /v1/mobile/activite/{type}/{id}`, scan
  `GET /v1/mobile/livraisons/scan/{reference}` (même périmètre), front Nuxt `elm-vitrine-nuxt`
  (menu, page `/espace-client/commandes`)

## Contexte

Le menu « Livraisons » du front Nuxt n'existait que pour les propriétaires et les livreurs, et listait
les opérations par véhicule. Un client ne voyait pas ses commandes, un livreur voyait tout l'historique
du véhicule de son équipe, même antérieur à son arrivée, et le statut brut (`cloturee`, « Commissions à
verser ») révélait le paiement à un livreur.

Une même vente peut être la commande d'un client, la livraison d'un livreur et la vente du véhicule d'un
propriétaire, parfois pour le même compte.

## Décision

1. **Une seule liste, sans rubrique** : le menu « Livraisons » devient « Commandes ». Une opération
   n'apparaît qu'une fois dans la liste d'un compte, même s'il y est lié à plusieurs titres. Aucun
   nouveau modèle : ventes (`CommandeVente`) et transferts (`TransfertLogistique`) restent séparés en
   base, fusionnés à la lecture.
2. **Trois informations distinctes, calculées par le serveur** :
   - `nature` : ce qu'est l'opération (vente, distribution client, précommande, transfert logistique) ;
   - `liens` : pourquoi ce compte la voit (`client`, `livreur`, `proprietaire`). Affichés « Ma
     commande », « Livraison », « Vente sur véhicule » ; un transfert reste « Transfert logistique » ;
   - `avancement` : un vocabulaire commun, traduit des statuts réels sans les modifier.
3. **Périmètre unique** (`OperationsCompte`), partagé par la liste, le détail et le scan. Le lien vient
   des données du compte, jamais de ses rôles : un super administrateur n'y gagne rien.
   - **Client** : ses commandes, hors brouillons.
   - **Propriétaire** : les opérations de ses véhicules.
   - **Livreur** : une opération s'il a une part de commission non annulée à son nom, si elle est en
     cours et confiée à son équipe actuelle, ou si elle est confiée à cette équipe et datée de son entrée
     au plus tôt. L'équipe est celle figée sur l'opération, sinon le véhicule de son équipe.
   - **Parrain** : ses ventes parrainées restent fermées ici (décision du 03/11/2026) ; il ne voit que
     ses commandes personnelles, depuis son Profil.
4. **Données selon le lien**, filtrées par le serveur :

   | Donnée | Client | Livreur | Propriétaire |
   |---|---|---|---|
   | Paiement | Oui | Non | Oui |
   | Lignes, quantités | Oui | Oui | Oui |
   | Prix, total | Oui | Non | Non |
   | Téléphone, adresse du client | Les siens | Pendant la livraison | Non |
   | Commissions | Jamais | Jamais | Jamais |

   Un compte qui cumule plusieurs liens reçoit l'union de ce que chaque lien autorise.
5. **Paiement et remise physique sont indépendants.** Une vente standard passe `livree` au premier
   encaissement, sans preuve de remise : elle s'affiche « En cours de clôture ». « Livrée » n'apparaît
   que pour une réception validée (`requiertReceptionExplicite`). Le champ `remise` n'est renseigné que
   pour une réception ou un retrait de précommande validés ; `remise_at` d'une précommande livrée date le
   chargement et n'est jamais présentée comme une remise. Les règles comptables du back-office ne
   changent pas, et le back-office continue d'afficher « Livrée » pour la même vente.
6. **Plus de filtre `statut`** : il permettait de déduire le paiement par essais successifs. Le filtre
   `lien` le remplace pour les comptes à plusieurs liens, côté serveur pour garder des totaux justes.
   `compte_a_des_operations`, calculé sans filtre, distingue un compte sans opération d'une période vide.
7. **Front** : la liste est ouverte à tout compte, même sans activité (capacité `orders`, exclue du test
   « compte sans activité »). Le bouton « Passer une commande » est toujours visible en haut, désactivé,
   « Bientôt disponible ». L'ancienne adresse `/espace-client/activite` redirige en conservant
   `?commande=`, pour les notifications et service workers déjà installés.

## Conséquences

- **Historique des livreurs** : il n'existe aucun historique des membres d'équipe, et
  `EquipeLivraisonController` supprime et recrée les membres à chaque modification. `created_at` peut
  donc être postérieur à l'arrivée réelle, jamais antérieur : un ancien membre peut perdre des opérations
  terminées sans part de commission, un nouveau membre ne récupère jamais l'historique de l'équipe.
  L'étendue réelle de cette perte doit être mesurée en production (requête de couverture des parts de
  livreur) avant de décider d'un éventuel traitement de l'historique.
- **Remise physique** : la connaître pour une vente standard demanderait une nouvelle action de
  confirmation, hors de ce chantier.
- **Transition** : `statut` et `statut_label` de la liste répètent `avancement`, pour le front déjà en
  ligne. Ils seront retirés une fois le nouveau front déployé. Le monolithe se déploie avant le front.
- **Hors périmètre** : l'application mobile (`livraisons-transferts`) garde son historique par équipe ; un
  nouveau propriétaire d'un véhicule peut hériter de son historique, comme avant (non vérifié en pratique).
