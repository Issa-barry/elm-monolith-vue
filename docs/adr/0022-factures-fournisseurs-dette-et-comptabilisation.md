# ADR 0022 — Factures fournisseurs : dette à la validation, comptabilisation par mapping

- **Date** : 2026-10-08
- **Statut** : **accepté le 2026-10-08** pour le mécanisme ; **comptes d'achat et TVA en attente de
  validation par le comptable** (voir « Décisions en attente »)
- **Périmètre** : lot 3 du module Achats (ADR 0021). Le paiement (lot 4) est hors périmètre.
- **Détail fonctionnel** : [docs/achats.md](../achats.md), section Factures fournisseurs

## Contexte

Les lots 1 et 2 (ADR 0021) livrent le bon de commande validé et les réceptions multiples, qui font
entrer le stock sans aucune écriture comptable. Il manquait la facture du fournisseur et la dette
qu'elle crée. Le moteur comptable existant (`EcritureComptableService`, `compta_mappings`) ne
contient ni compte fournisseur, ni compte d'achat, ni TVA ; le journal `AC` existe mais n'a jamais
servi.

## Décision

1. **Facture distincte de la réception**, rattachée à UN bon de commande validé et à son
   fournisseur (fournisseur saisi = fournisseur du bon, sinon refus).
2. **Elle facture des lignes de réception** (une ou plusieurs réceptions). Quantité facturable
   d'une ligne reçue = reçu − déjà facturé sur des factures validées. Contrôlé à la saisie, puis
   **sous verrou des lignes de réception à la validation** : une quantité reçue n'est jamais
   facturée deux fois, même par deux brouillons validés en concurrence. Les réceptions ne sont
   jamais modifiées. Sous MySQL (InnoDB, REPEATABLE READ), le « déjà facturé » est lu par une
   **lecture verrouillante en jointure** (`FOR UPDATE`, jamais une sous-requête `whereHas`, qui
   resterait lue dans la vue cohérente de la transaction et ignorerait une validation concurrente
   commitée entre-temps) — prouvé par `FactureFournisseurConcurrenceTest` (deux connexions MySQL
   réelles, test rouge avant correctif).
2bis. **Numéro de facture du fournisseur unique par fournisseur, garanti en base** : clé technique
   `cle_numero_unique` (= numéro tant que la facture n'est pas annulée, NULL une fois annulée) et
   index unique (organisation, fournisseur, clé). Une saisie simultanée du même numéro est refusée
   par la base et rendue comme une erreur de saisie ; un numéro annulé peut être ressaisi.
3. **Statuts** : brouillon → validée → partiellement payée / payée (lot 4) ; annulée (brouillon, ou
   validée sans paiement, avec contrepassation).
4. **La dette naît à la validation**, jamais à la réception ni au brouillon. Elle est portée par la
   facture : HT, TVA, TTC, montant payé (0 au lot 3), reste dû = TTC − payé. Dette d'un fournisseur =
   somme des restes dus de ses factures. Montants, libellés et nom du fournisseur figés.
5. **Validation** : permission `factures-fournisseurs.valider` + agence du bon couverte par le
   périmètre « Peut acheter pour » + ni l'auteur de la saisie ni le dernier modificateur. Aucun
   passe-droit de rôle (super administrateur compris) ; contrôle de périmètre explicite dans les
   contrôleurs (Gate::before).
6. **Comptabilisation par le moteur commun, à la validation**, événement
   `facture_fournisseur_validee`, journal résolu par mapping :
   - Débit `achat_{code du type de produit}`, repli `achat` — montant HT ;
   - Débit `tva_deductible` — montant de TVA, si > 0 ;
   - Crédit `fournisseur` (tiers = fournisseur) — montant TTC.
   Aucun numéro de compte dans le code. Seul `fournisseur` → **401000 Fournisseurs, journal AC** est
   provisionné (bootstrap + migration).
7. **Comptabilisation non bloquante** (comme `vente_facturee`) : tant qu'un compte d'achat ou de TVA
   n'est pas mappé, la facture est validée et la dette existe, mais la pièce n'est pas passée ; le
   motif est conservé sur la facture et affiché, avec un bouton « Relancer ». `comptabilite:rattraper
   --type=facture-fournisseur` passe les pièces manquantes une fois les comptes paramétrés.
7bis. **État comptable distinct du statut de la facture**, affiché sur la fiche et dans la liste :
   « Comptabilisée » (pièce passée), « En attente de paramétrage » (facture validée sans pièce),
   « Contrepassée » (facture annulée après comptabilisation, pièce inverse référencée par
   `piece_origine_id`), « Pas encore / Aucune écriture ». Une facture validée n'est jamais présentée
   comme comptabilisée sans pièce. Le rattrapage est idempotent (une pièce par facture et par
   événement, garantie par `compta_pieces_idempotency_unique`).
8. **TVA** : taux saisi par facture, 0 par défaut (l'application ne gérait aucune TVA jusqu'ici).

## Décisions en attente (comptable)

- Compte(s) d'achat : un seul compte (`achat`, ex. 601) ou un par type de produit
  (`achat_materiel`, `achat_matiere_production`, `achat_achat_vente` — ex. 602 / 604 / 601 / 608) ?
- TVA : les factures fournisseurs portent-elles une TVA récupérable ? Si oui, quel compte
  (`tva_deductible`, ex. 4452) et quels taux ?
- Confirmation du compte 401000 et du journal AC pour la dette fournisseur.
- Faut-il, en plus, comptabiliser le stock (classe 3) à la réception ? Aujourd'hui : non (stock
  physique seulement, comme pour les ventes).

Une fois tranchées : ajouter les lignes de mapping (bootstrap + migration, comme pour les autres
événements), puis lancer `php artisan comptabilite:rattraper --type=facture-fournisseur`.

## Conséquences

- Nouvelle ressource de permissions `factures-fournisseurs` (CRUD) + `valider` / `annuler`.
  Matrices par défaut (migration de complément, ADR 0011) : admin_entreprise toutes ; comptable
  lire, saisir, modifier, valider, annuler. Le comptable doit aussi avoir une règle « Peut acheter
  pour » (Paramètres → Achats) pour voir et valider les factures de ses agences.
- Les écarts de prix entre la facture et le bon sont acceptés et signalés en orange ; aucune
  re-validation n'est exigée au-delà du montant validé du bon (à revoir si besoin).
