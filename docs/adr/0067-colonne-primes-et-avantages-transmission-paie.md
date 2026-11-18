# ADR 0067 — Colonne « Primes et avantages » de la transmission de paie

- **Date** : 2026-11-17
- **Statut** : accepté le 2026-11-17 (décision de l'utilisateur)
- **Complète** : [ADR 0040](0040-transmission-paie-figee-versionnee.md) (transmission figée et
  versionnée) et [ADR 0046](0046-prime-categorie-unique.md) (une seule catégorie : Prime)
- **Détail fonctionnel** : [docs/transmission-paie.md](../transmission-paie.md), § Totaux affichés

## Contexte

L'écran Transmission de paie affichait une colonne « Avantages valorisés ». Elle totalisait les
lignes **valorisées**, c'est-à-dire les avantages en nature : leur valeur est estimée mais n'est
jamais payée au salarié, et elle n'entre pas dans le net à payer (TRANS-006, ADR 0038).

Depuis l'ADR 0046, tout type de rémunération créé est une prime, payée en argent. Aucun avantage en
nature ne peut plus être créé. La colonne restait donc à 0 GNF. Par exemple, octobre 2026 en
formation : 0 GNF, alors que Moussa Sidibe avait deux primes, vacance (200 000 GNF) et TRANSPORT
(500 000 GNF).

Pour EAU LA MAMAN, une prime attribuée à un salarié est un avantage accordé par l'entreprise. Le
terme « avantage » de la colonne avait été compris dans son sens technique, « non payé ». Le métier
l'entend comme « accordé ». Le paiement des primes, lui, était correct.

## Décision

1. **Définition** : la colonne présente le cumul de **toutes les primes et de tous les avantages
   accordés** aux salariés sur le mois, payés en argent (primes) ou fournis en nature. Ce sont toutes
   les lignes d'origine *affectation* d'une version, c'est-à-dire tout sauf le salaire de base.
2. **Intitulé** : « Primes et avantages » remplace « Avantages valorisés ». Une infobulle précise que
   les primes payées sont déjà comprises dans le total versé : **les deux totaux ne s'additionnent
   pas**.
3. **Périmètre** : la liste des transmissions, et dans le détail d'une version le total de
   l'organisation, le total des salariés visibles ou filtrés, et le total de chaque salarié.
4. **Affichage seulement** : le montant est calculé à la lecture à partir des lignes déjà
   enregistrées. Rien n'est stocké, rien n'est recalculé.
5. **Inchangés** : la classification *versé* / *valorisé* de chaque ligne, le total versé, le total
   valorisé stocké (`transmissions_paie.total_valorise`), l'empreinte, le net à payer, les bulletins,
   la reprise comptable (`paie_lignes.total_avantages_nature`, contrôle de concordance) et les
   versions déjà validées et figées.

## Options écartées

- **Classer les primes comme valorisées** : elles sortiraient du net à payer, et la Comptabilité ne
  les paierait plus. Le contrôle de concordance de la reprise changerait de sens. Toutes les versions
  figées paraîtraient modifiées, car leur empreinte dépend du versement.
- **Stocker un nouveau total** : il faudrait une migration et une valeur à reconstituer pour les
  versions passées. Or le calcul à la lecture suffit et s'applique aussitôt aux anciennes versions.

## Conséquences

- Les anciennes transmissions, octobre 2026 compris, affichent les primes déjà enregistrées, sans
  modification de leurs données.
- Un avantage en nature créé avant l'ADR 0046 reste valorisé : il compte dans « Primes et
  avantages » mais pas dans le total versé.
- L'écran Comptabilité → Salaires et la fiche salarié gardent leur colonne « Avantages en nature »,
  qui ne contient que les avantages non payés. Les primes y figurent déjà dans la colonne « Primes ».
