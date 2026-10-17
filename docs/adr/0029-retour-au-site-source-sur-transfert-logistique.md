# ADR 0029 — Retour de marchandise au site source sur un transfert logistique

- **Date** : 2026-10-16
- **Statut** : accepté le 2026-10-16 (arbitrages A1 à A5 validés par le propriétaire du produit) —
  lot 1 (backend) et lot 2 (écran) livrés le 2026-10-16
- **Périmètre** : `TransfertLogistique`, retour au site source avant clôture — cf.
  [retour-transfert.md](../retour-transfert.md)
- **S'inspire de** : [ADR 0003](0003-retour-de-livraison-avant-encaissement.md) (retour de livraison
  d'une vente standard)

## Contexte

Entre le départ et l'arrivée d'un transfert, tout ou partie de la marchandise peut revenir au site
source : refus à la destination, erreur de chargement, transfert impossible. Le départ (CHARGEMENT →
TRANSIT) sort la quantité chargée du stock source ; la réception (TRANSIT → RECEPTION) n'entre que la
quantité reçue au stock destination. La différence n'était réintégrée nulle part et aucun mécanisme
ne traçait un retour. La Vente dispose depuis l'ADR 0003 d'un retour de livraison : ENTRÉE de stock
distincte, motif obligatoire, l'enregistrement par un utilisateur habilité vaut validation.

## Décision

1. **Un retour est un mouvement métier tracé**, jamais une correction des quantités chargée et
   reçue : `transfert_retours` (auteur, date, motif, commentaire, site de réintégration, quantité
   totale, retour total) et `transfert_retour_lignes` (ligne, variante, quantité, libellé figé) ;
   `transfert_lignes.quantite_retournee` cumule les retours. Ni prix ni montant : un transfert ne
   porte jamais de facture (COMM-006).
2. **Statuts éligibles : TRANSIT et RECEPTION.** Refusé en BROUILLON/CHARGEMENT (rien n'est parti),
   ANNULE, CLOTURE et RETOURNE. Source de vérité unique : `TransfertLogistique::raisonRetourImpossible()`.
3. **Quantités** — notations C = chargée, R = reçue, T = retournée (cumul) :
   - en TRANSIT (réception non effective) : retournable = C − T ;
   - en RECEPTION : retournable = C − R − T (seul le non-reçu peut revenir) ;
   - à la réception (TRANSIT → RECEPTION), si T > 0 : R ≤ C − T. Si T = 0, la règle antérieure
     s'applique, surplus compris (**A1**) ;
   - **écart = R + T − C** (manquant si négatif, surplus si positif) — identique à l'ancien R − C
     quand T = 0, donc aucun historique ne change. Le type d'écart qualifie cet écart net ;
   - l'invalidation d'une réception reste permise (**A2**) : le transfert revient en TRANSIT, les
     retours restent acquis, la nouvelle réception est bornée à C − T.
4. **Stock** : une ENTRÉE au **site source** par ligne de retour
   (`MouvementStockService::reintegrerRetour()`, source `TransfertRetourLigne`), la SORTIE du départ
   reste intacte. Aucun filtre sur le type de produit, symétrique de `enregistrerSortieSource()`. La
   marchandise abîmée suit le circuit de casse existant (écart `CASSE` à la réception, ajustement de
   stock « Casse » au site source).
5. **Retour total en TRANSIT** (T = C sur toutes les lignes) : le transfert passe au **statut
   terminal `retourne`** (« Retourné », orange — **A5**). En RECEPTION, le statut reste RECEPTION.
6. **Commissions — COMM-007 maintenue, aucun recalcul** (**A3**) :
   - `RECEPTION_EFFECTUEE` : la commission porte sur R, déjà net des retours ; un transfert
     `retourne` n'atteint jamais la réception et ne génère rien ;
   - `CHARGEMENT_VALIDE` : la commission générée au départ sur C **reste due, même si toute la
     marchandise revient**. Conséquence assumée par le propriétaire du produit, provisoire : toute
     évolution exigera une décision explicite et la mise à jour de COMM-007 ;
   - le monitoring des commissions traite `retourne` comme `annule` : aucune génération manquante
     n'est relancée.
7. **L'enregistrement vaut validation** ; ni suppression, ni annulation, ni correction de retour dans
   ce lot (**A4**). Une erreur de stock se corrige par un ajustement de stock tracé ; une opération
   compensatoire dédiée pourra être conçue plus tard.
8. **Motifs** (`MotifRetourTransfert`) : refus de réception, erreur de chargement, livraison ou
   transfert impossible, autre (commentaire obligatoire). Pas de « produit endommagé » (cf. point 4).
9. **Permission dédiée `logistique.enregistrer_retour`** (STANDALONE, Logistique > Chargement /
   Réception). Policy : permission + même organisation + transfert retournable + rattachement au
   **site source** (qui constate le retour physique et dont le stock est réintégré) ou `isAdmin()`.
   Préréglages : `admin_entreprise`, `manager` ; migration de données l'accordant aux rôles existants
   qui ont `logistique.update` (ADR 0011).
10. **Concurrence** : retour (`TransfertRetourService`), transitions (`avancerStatut()`), saisie des
    lignes (web et API) et invalidation (web et API) s'exécutent en transaction sous verrou de la
    ligne du transfert ; les quantités sont relues sous ce verrou. Une double soumission ou un retour
    concurrent d'une réception est refusé par le contrôle de quantité. La quantité chargée n'est plus
    modifiable hors CHARGEMENT, ni la réception hors TRANSIT.

## Alternatives écartées

- **Rester en TRANSIT après un retour total** : le transfert serait resté « à réceptionner » et
  « livraison en cours » à vie (badge Réceptions, API client `LivraisonsEnCours`/`Vehicules`, API
  mobile `MesLivraisons`).
- **Réintégrer au site destination / traiter le renvoi après réception comme un retour** : c'est un
  transfert inverse, à étudier séparément.
- **Recalculer la commission comme en Vente** : contraire à COMM-007 sans décision explicite.
- **Toujours borner R à C − T** : aurait supprimé le surplus, écart aujourd'hui prévu.
- **Refuser l'invalidation dès qu'un retour existe** : bloquant sans nécessité, la borne à la
  nouvelle réception suffit à garder les quantités cohérentes.

## Conséquences

- Migration de schéma non destructive (`2026_10_16_100000`) et migration de données de permission
  (`2026_10_16_100100`).
- Nouveau statut `retourne` : `StatutTransfert` (libellé, couleur, point, `isTerminal`), vue
  Transferts de la liste, historique de l'API mobile, monitoring des commissions. La clé `retourne`
  de `StatusDot` reste rouge (mouvement de fonds) : les écrans de transfert passent la couleur
  calculée côté serveur (`statut_dot_class`).
- `TransfertLigne::ecart` passe à R + T − C (inchangé sans retour). Les API exposent
  `quantite_retournee` en plus.
- `avancerStatut()` travaille sur une copie verrouillée puis rafraîchit l'instance de l'appelant.
- Hors périmètre : renvoi depuis la destination après réception (transfert inverse), correction d'un
  retour, saisie depuis l'application mobile.
