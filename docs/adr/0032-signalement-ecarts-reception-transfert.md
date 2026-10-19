# ADR 0032 — Signalement des écarts de réception d'un transfert logistique

- **Date** : 2026-10-18
- **Statut** : accepté le 2026-10-18 — livré le 2026-10-18
- **Périmètre** : `TransfertLogistique`, réception (TRANSIT → RECEPTION) — cf.
  [retour-transfert.md](../retour-transfert.md), section « Réception et écarts »
- **Complète** : [ADR 0029](0029-retour-au-site-source-sur-transfert-logistique.md) (définition de
  l'écart net)

## Contexte

À la réception, l'agence destinataire peut déclarer une quantité reçue différente de la quantité
chargée. La quantité manquante n'est réintégrée nulle part et doit, pour le moment, rester traitée
manuellement. Personne n'était prévenu : l'écart n'apparaissait que sur la fiche du transfert.

## Décision

1. **Signalement seulement.** Aucun stock, mouvement, commission, statut ni étape du workflow n'est
   modifié. Pas de retour, de réintégration ni de régularisation automatique.
2. **Ligne en écart** : écart net (reçu + retourné − chargé, ADR 0029) non nul, **ou** type d'écart
   déclaré autre que « Conforme » (casse ou perte comptée comme reçue). Le surplus est signalé. La
   marchandise revenue au site source n'est pas un écart. Une réception sans aucune ligne en écart
   ne signale rien.
3. **Un signalement par réception et par destinataire**, regroupant toutes les lignes en écart :
   produit et variante, chargé, réceptionné, revenu au site source, non réceptionné ou surplus, type
   et motif ; agences source et destination, réceptionnaire, date, lien vers la fiche.
4. **Destinataires**, dédoublonnés (une seule notification par personne) :
   - le **réceptionnaire** (utilisateur connecté au moment de la transition) — dérogation volontaire
     à la convention « l'auteur n'est pas notifié de sa propre action » : le signalement lui sert de
     trace ;
   - le **créateur** du transfert (`created_by`) ;
   - l'**agence source** : utilisateurs actifs rattachés au site source ayant `logistique.update`,
     la permission que la policy vérifie pour gérer un transfert depuis son agence. Le transfert ne
     mémorise pas d'« agence créatrice » : le site source est imposé au créateur non administrateur
     (son site par défaut). `logistique.valider_reception` existe au catalogue mais n'est vérifiée
     par aucune policy : elle n'est pas utilisée ;
   - les **administrateurs** (`super_admin`, `admin_entreprise`) de l'organisation, comme les alertes
     de stock.

   Tous de l'organisation du transfert, utilisateurs inactifs exclus. Préférence `livraisons`
   respectée (mécanisme commun `NotificationDispatcher`).
5. **Canaux** : notification dans l'application (table `notifications` : cloche du back-office web
   et API mobile `GET /v1/mobile/notifications`, type `transfer.reception_discrepancy`), push (Expo
   et Web Push) et **email**. L'email part après la notification et destinataire par destinataire :
   un SMTP indisponible ou saturé ne prive personne de la notification dans l'application et ne
   remonte jamais vers la réception (erreur journalisée).
6. **Déclenchement** : constat établi dans la transition TRANSIT → RECEPTION
   (`TransfertLogistiqueService::avancerStatut()`, sous verrou), envoi après le commit
   (`DB::afterCommit`, pas de file d'attente : aucun worker en production). Couvre l'écran web et
   l'API mobile. Une réception refusée ou annulée ne signale rien ; une double soumission ne repasse
   jamais par la transition.
7. **Invalidation** : les signalements encore non lus de la réception invalidée sortent de la cloche
   (marqués lus) ; l'email déjà parti ne peut être rappelé. La nouvelle réception signale de nouveau
   si un écart subsiste et précise qu'il s'agit d'une nouvelle réception.
8. **Cloche du back-office** : section « Écarts de réception » (ambre : attention, opération
   autorisée), 5 derniers non lus ; un clic marque le signalement lu et ouvre la fiche du transfert.

## Conséquences

- Code : `App\Services\TransfertEcartReceptionService`,
  `App\Notifications\EcartReceptionTransfertNotification`,
  `App\Http\Controllers\Logistique\OuvrirEcartReceptionController`, prop partagée
  `ecarts_reception` (`HandleInertiaRequests`), `AppSidebarHeader.vue`.
- Tests : `tests/Feature/TransfertEcartReceptionNotificationTest.php`.
- Un compte sans email n'est notifié que dans l'application.
- La gestion de l'écart lui-même (retour, régularisation de stock) reste manuelle ; une
  automatisation éventuelle fera l'objet d'un ADR distinct.
