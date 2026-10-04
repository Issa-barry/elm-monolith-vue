# ADR 0018 — Approvisionnement de la caisse d'un agent depuis la caisse de l'agence

- **Date** : 2026-10-04
- **Statut** : accepté, livré le 2026-10-04
- **Périmètre** : mouvements de fonds (nature `approvisionnement_caisse`), Trésorerie → Supports,
  Mouvements, Ma situation, fiche caisse — cf. [data-dictionary-compta.md](../data-dictionary-compta.md)
  (« Approvisionnement de la caisse d'un agent »), [rapports.md](../rapports.md), ADR 0001, 0009.

## Contexte

Depuis l'ADR 0009, payer une commission en espèces fait sortir l'argent de la **caisse dédiée du
payeur**. Une caisse dédiée ne s'alimentait que par les encaissements en espèces de son agent, et
l'argent ne circulait que dans un sens : caisse de l'agent → caisse de l'agence (versement). Les
espèces de la caisse de l'agence ne pouvaient donc servir à aucun paiement en espèces : un
responsable sans encaissements personnels ne pouvait pas payer une commission (« Solde insuffisant :
0 GNF disponible »), constaté le 03/10/2026. Le code annonçait pourtant « son alimentation passe par
un transfert depuis la caisse de l'agence », jamais construit.

## Décision

1. **Nouvelle nature de mouvement `approvisionnement_caisse`** (« Approvisionnement de caisse ») :
   caisse (type Caisse) **d'agence** active → **caisse dédiée active** d'un agent, **même agence**.
   Nature distincte du versement (`interne_caisses`) pour ne jamais fausser les calculs qui supposent
   le sens agent → agence (en cours de versement, Financement, remises ADR 0016).
2. **Même circuit que le versement** : créé et envoyé en une seule action depuis Supports
   (« Approvisionner un agent ») ; Envoyé → Reçu, ou Contesté → Retour confirmé ; mêmes écritures via
   le compte de transit 588000 ; solde de la caisse source contrôlé sous verrou à l'envoi.
3. **Initier = `tresorerie.envoyer`** sur son agence (admins : toutes). Aucune nouvelle permission.
4. **Réception par le titulaire (décision utilisateur du 04/10/2026)** : **seul l'agent titulaire de
   la caisse destinataire** confirme ou conteste. Ni un tiers ayant `tresorerie.recevoir`, ni un
   administrateur, ni un super administrateur ne peuvent le faire à sa place. Règle d'**identité**,
   sans permission requise (l'agent n'a pas besoin d'accès à la trésorerie), garantie par le service
   (`MouvementFonds::receptionReserveeA()`) indépendamment du `Gate::before` du super admin.
   Objectif : la confirmation de l'agent est la preuve de la remise physique des espèces.
5. **Retour** : constaté côté agence (`tresorerie.confirmer_retour`), jamais par l'agent bénéficiaire.
6. **Traçabilité** : remis par (`sent_by`), reçu par (`received_by`) et l'**heure** de chaque étape
   (`sent_at`, `received_at`, nouvelles colonnes renseignées pour tout mouvement à partir de ce jour).
7. **Où l'agent confirme** : « Ma situation » (accessible à tous les rôles), bloc « Espèces à
   confirmer » + badge de menu ; aussi dans Mouvements s'il y a accès.
8. **Sa propre caisse (révision du 04/10/2026, remplace « l'envoyeur n'est jamais le
   bénéficiaire »)** : les responsables (admins, managers) gèrent à la fois leur caisse dédiée et
   celle de l'agence. Ils peuvent approvisionner leur propre caisse et confirmer eux-mêmes la
   réception, à condition que leur rôle ait `tresorerie.recevoir` (contester : `tresorerie.rejeter`),
   exactement comme l'auto-confirmation d'un versement (ADR 0001). Règle par permission, jamais par
   nom de rôle. Sans `tresorerie.recevoir`, l'envoi vers sa propre caisse est refusé (l'argent
   resterait bloqué en transit). L'auto-confirmation est tracée (« Confirmé par l'expéditeur »).

## Conséquences

- **Exception assumée à l'ADR 0001** : le versement agent → agence garde sa règle (simple permission,
  auto-confirmation tracée) ; l'approvisionnement agence → agent est réservé au titulaire de la
  caisse. Seul cas où remettant et bénéficiaire sont la même personne : sa propre caisse (point 8),
  alors aligné sur le versement.
- Tant que l'agent n'a pas confirmé, le montant n'est dans aucun solde et ne peut pas être dépensé ;
  une caisse d'agent ne peut pas être désactivée avec un approvisionnement en attente.
- Un agent absent bloque l'approvisionnement en « Envoyé » : seule sa contestation suivie d'un retour
  constaté par l'agence libère l'argent. Aucun mécanisme d'expiration automatique.
- Pas d'approvisionnement direct entre deux caisses d'agents : l'argent repasse par la caisse de
  l'agence (versement puis approvisionnement).
- Les mouvements antérieurs au 04/10/2026 n'ont pas d'heure d'envoi ni de réception (colonnes nulles).
