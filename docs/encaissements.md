# Encaissements de vente

## Modes de paiement — `App\Enums\ModePaiement`

`especes`, `mobile_money`, `virement`, `cheque`. Stable et indépendant de l'opérateur
utilisé (voir ci-dessous) : ne pas y ajouter une valeur par opérateur/fintech.

## Référence de paiement obligatoire (2026-09-14)

Champ `reference_paiement` (nullable, string 190) sur `encaissements_ventes`, requis
côté backend (`StoreEncaissementVenteController`) via `required_if:mode_paiement,...`
uniquement pour :

- **Mobile Money** — identifiant de la transaction opérateur.
- **Virement** — référence bancaire, nécessaire au rapprochement.

Facultatif pour **Chèque** et **Espèces** (pas de rapprochement structuré identifié à ce jour ;
un numéro de chèque dédié pourra être ajouté plus tard si un besoin métier confirmé apparaît).

Le champ `note` reste un commentaire libre, distinct de la référence — il n'est pas utilisé
pour cette règle métier.

## Opérateur Mobile Money — `App\Enums\OperateurMobileMoney`

Champ `operateur_mobile_money` (nullable), requis uniquement quand `mode_paiement = mobile_money`.
Valeurs : `orange_money`, `kulu`, `soutra_money`, `momo`, `paycard`, `autre`.

Volontairement **séparé** de `mode_paiement` : le marché guinéen compte plusieurs fintechs
Mobile Money actives ou émergentes (Orange Money, Kulu, Soutra Money, MTN MOMO, PayCard...).
Faire de chaque opérateur une valeur de `mode_paiement` obligerait à modifier cet enum — et
tout le code qui filtre/agrège dessus (rapports, contrôleurs Index, `Rule::in`) — à chaque
nouvel entrant, et ferait disparaître la notion générique "Mobile Money" utilisée ailleurs.
Un nouvel opérateur non listé peut être saisi via la valeur `autre` en attendant un ajout à
l'enum si le volume le justifie.

## Backend comme source de vérité

La validation (référence/opérateur obligatoires selon le mode) est portée exclusivement par
`StoreEncaissementVenteController` (validation inline, pas de FormRequest dédié pour ce
contrôleur). Le frontend (`resources/js/pages/Ventes/Show.vue`) reproduit ces règles pour
l'UX (champs conditionnels, bouton désactivé) mais n'est jamais la seule protection.

## Historique affiché

`ShowCommandeVenteController` expose `operateur_mobile_money`, `operateur_mobile_money_label`
et `reference_paiement` dans le payload `facture.encaissements[]`, affichés dans l'historique
des encaissements (onglet Facturation de `Ventes/Show.vue`).
