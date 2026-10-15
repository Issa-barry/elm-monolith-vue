# ADR 0026 — Dépôt en banque depuis une caisse ou un Mobile Money de l'agence

- **Date** : 2026-10-13
- **Statut** : accepté, livré le 2026-10-13 (demande de l'utilisateur : « les caisses espèces et
  Mobile Money doivent pouvoir faire des mouvements vers une caisse de type banque »)
- **Complète** : [ADR 0018](0018-approvisionnement-caisse-agent.md), qui notait ce cas « non couvert »
- **Périmètre** : mouvements de fonds (nature `depot_banque`), Mouvements → Nouveau, liste des
  Mouvements — cf. [data-dictionary-compta.md](../data-dictionary-compta.md) (« Dépôt en banque »).

## Contexte

Entre deux agences, un mouvement de fonds part de n'importe quel support et arrive sur le support
que le destinataire choisit à la réception : caisse → banque y était déjà possible. **Dans une même
agence**, en revanche, « Nouveau mouvement de fonds » n'acceptait qu'un seul cas —
l'approvisionnement de la caisse d'un agent depuis une caisse de l'agence (ADR 0018). Une agence ne
pouvait donc pas enregistrer le dépôt de ses espèces ou de son solde Mobile Money sur **sa propre
banque** : l'argent restait affiché en caisse alors qu'il était physiquement à la banque.

## Décision

1. **Nouvelle nature de mouvement `depot_banque`** (« Dépôt en banque ») : caisse (type Caisse) ou
   compte Mobile Money **de l'agence**, actif → **banque active de la même agence**. Nature distincte,
   comme l'ADR 0018 l'avait fait pour l'approvisionnement : les calculs existants filtrent chacun sur
   leur nature (en cours de versement, approvisionnements à confirmer, fonds en transit et déjà
   financé du Financement, remises ADR 0016) et ne sont donc pas modifiés.
2. **Même circuit que le versement et l'approvisionnement** : créé et envoyé en une seule action
   (pas de brouillon, donc pas d'annulation) ; Envoyé → Reçu, ou Contesté → Retour confirmé ; mêmes
   écritures via le compte de transit 588000 ; solde du support source contrôlé sous verrou à l'envoi.
   La banque est fixée à l'envoi : la réception la confirme sans pouvoir la remplacer.
3. **Point d'entrée** : Mouvements → Nouveau, **même agence** en origine et en destination. Le
   support de destination choisi décide de l'opération : une banque de l'agence = dépôt en banque ;
   la caisse d'un agent = approvisionnement (ADR 0018, inchangé).
4. **Initier = `tresorerie.create` (accès à l'écran) + `tresorerie.envoyer` sur son agence**
   (admins : toutes) — `CompteTresoreriePolicy::deposerEnBanque()`. Aucune nouvelle permission.
5. **Confirmer = `tresorerie.recevoir` sur l'agence, l'envoyeur compris.** C'est la règle du
   versement (ADR 0001) : pas de séparation par personne, l'auto-confirmation est tracée (« Confirmé
   par l'expéditeur »). Contester : `tresorerie.rejeter` ; constater le retour :
   `tresorerie.confirmer_retour`. La règle « seul le titulaire confirme » de l'ADR 0018 ne
   s'applique pas : une banque n'a pas de titulaire.
6. **Bordereau** : la référence externe et le justificatif sont proposés (facultatifs). Le moyen de
   transfert est déduit du support source (`especes` ou `mobile_money`) ; la période de financement
   ne s'applique pas.
7. **Notification** : un dépôt Envoyé compte dans le badge « Mouvements à confirmer » de l'agence,
   comme un mouvement entre agences — sinon un dépôt envoyé par quelqu'un qui n'a pas
   `tresorerie.recevoir` resterait en transit sans que personne soit prévenu.

## Conséquences

- Tant que le dépôt n'est pas confirmé, son montant n'est dans **aucun** solde (ni le support
  source, ni la banque) : il est au compte de transit 588000. Le disponible de l'agence utilisé par
  le Financement baisse donc d'autant jusqu'à la confirmation, exactement comme pendant un
  approvisionnement en attente.
- Les frais éventuels d'un dépôt (commission Mobile Money, frais bancaires) ne sont pas saisis ici :
  le montant crédité à la banque est le montant envoyé.
- **Non couvert, volontairement** (non demandé) :
  - le sens inverse (retrait banque → caisse) et tout autre couple de supports de l'agence
    (caisse ↔ Mobile Money) : une banque en origine reste refusée dans une même agence ;
  - le dépôt direct depuis la **caisse d'un agent** : elle se verse d'abord à la caisse de l'agence
    (versement), qui dépose ensuite ;
  - un bouton « Déposer en banque » sur l'écran Supports : le seul point d'entrée est Mouvements →
    Nouveau.
