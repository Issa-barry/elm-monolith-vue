# ADR 0020 — Agence qui paie une commission livreur/propriétaire : site actuel du véhicule

- **Date** : 2026-10-06
- **Statut** : **accepté le 2026-10-06** (décision de l'utilisateur après constat en production)
- **Périmètre** : fiches de paiement livreur/propriétaire, écrans Commissions Livreurs et
  Propriétaires (filtre Agence, colonne Agence, exports), Financement / Remises des agences
  (obligations par agence), paiement d'une fiche (ADR 0009)
- **Amende** : le choix provisoire « agence source du transfert » de
  `CommissionLogistiqueService::resolveSiteResponsable()` (marqué « décision non tranchée »)

## Contexte

En production, le véhicule Diaraye (BT2586), rattaché à Matoto, charge et vend dans cinq usines
(Cba, Lambanyi, Matoto, Sonfonia, Tombolia). L'agence d'une commission était le **site de la vente**
(`commandes_ventes.site_id`) :

- l'écran Commissions des livreurs d'un manager de Cba affichait les commissions de Diaraye
  réalisées à Cba (« Agence : Cba »), le super administrateur voyait les cinq sites ;
- la fiche de paiement de la période prenait le site **majoritaire en montant des ventes**,
  présenté dans le code comme un cas « rare, remplacement ponctuel » — c'est en réalité le
  fonctionnement normal de ces véhicules ; l'agence qui paie changeait d'une quinzaine à l'autre.

## Décision

1. **L'agence qui paie une commission livreur ou propriétaire est le site actuel du véhicule**,
   quel que soit le site où la commission a été générée (vente ou transfert logistique).
2. **Ce site n'est jamais figé** : tant que la commission n'est pas entièrement payée, elle suit
   le véhicule. Une réaffectation après la période, après la validation ou après un paiement
   partiel déplace le reste à payer vers la nouvelle agence. Les paiements déjà faits gardent
   leur site.
3. **Repli** : véhicule sans site (ou supprimé) → site de la vente, agence source du transfert.
4. **Une fiche ne porte qu'une agence** : un bénéficiaire passé sur deux véhicules d'agences
   différentes dans la période est rattaché à l'agence qui pèse le plus en montant.
5. Le site de la vente reste une information d'analyse (sites contributeurs d'un véhicule sur
   l'écran Propriétaires, site de chaque commande dans le détail d'un bénéficiaire) ; il ne
   désigne plus jamais l'agence qui paie. Les commissions de la cible **Site** ne sont pas
   concernées : elles restent dues au site de la vente.

## Mise en œuvre

- `CommissionEnveloppe::siteResponsableId()` et `CommissionLogistiqueService::resolveSiteResponsable()`
  — règle unique en PHP ; `CommissionSiteResponsableFilter` — même règle en SQL (filtre Agence et
  périmètre d'un non-administrateur sur les écrans Livreurs/Propriétaires et leurs exports).
- `FicheSiteResponsableService` : site d'une fiche depuis ses lignes ; réalignement des fiches
  non payées d'un véhicule, appelé par `Vehicule::booted()` dès que `site_id` change (formulaire
  véhicule, import flotte, import de mise à jour des véhicules — ces deux imports passent
  désormais par le modèle).
- Rattrapage des fiches calculées avant la règle :
  `php artisan commissions:realigner-sites-fiches` (aperçu), puis `--appliquer`.

## Conséquences

- Le manager de l'agence du véhicule voit et paie toutes ses commissions ; un manager d'une autre
  agence ne les voit plus, même si les ventes ont eu lieu chez lui.
- Le besoin « livreurs/propriétaires » du Financement et des Remises suit le véhicule.
- **Comptabilité (complété le 2026-10-07)** : une fiche déjà constatée (pièce de validation) qui
  change d'agence voit son **reste dû et la charge correspondante** passer de l'agence d'origine à
  la nouvelle — `paiement_fiche_reaffectations` (une ligne par changement, trace conservée) et deux
  pièces mono-site `fiche_reaffectee_sortie` (origine : débit dette 467110/467120, crédit charge
  622100/622200) et `fiche_reaffectee_entree` (destination : débit charge, crédit dette). La dette
  est ainsi soldée là où elle est payée, et chaque agence porte la charge de ce qu'elle paie (la
  part déjà payée par l'origine y reste). **Pas de compte de liaison 181** : la dette entre
  agences est dérivée des encaissements (ADR 0012), un solde de liaison né d'une réaffectation ne
  serait jamais réglé. Mode shadow, comme la constatation : un échec comptable est journalisé et
  ne bloque jamais le changement d'agence. Une fiche non constatée change d'agence sans écriture.
  La commande de rattrapage passe par le même chemin (`FicheSiteResponsableService::changerSite()`).
- Tests : `tests/Feature/Comptabilite/CommissionSiteResponsableVehiculeTest.php`,
  `ObligationsAgenceServiceTest::test_commission_comptee_sur_le_site_actuel_du_vehicule_pas_sur_le_site_de_la_vente`.
