# ADR 0046 — Une seule catégorie à la création des types : Prime

- **Date** : 2026-10-31
- **Statut** : accepté (demande de l'utilisateur du 31/10/2026)
- **Périmètre** : Paramètres → Primes et avantages, Primes des salariés — cf.
  [primes-avantages.md](../primes-avantages.md), ADR 0038

## Contexte

Le formulaire demandait de choisir entre Prime, Indemnité, Allocation et Avantage en nature. Pour
l'organisation, ces distinctions ne servent pas à la saisie : transport, logement, rendement,
ancienneté… sont tous des primes. Dans le code, Prime, Indemnité et Allocation ont exactement le
même traitement (versées en argent) ; seul l'avantage en nature diffère (valorisé, jamais versé
dans le net à payer : `CategorieTypeRemuneration::estValorise()`, lu par la transmission de paie).

## Décision

1. Le formulaire ne demande plus de catégorie ; le serveur n'en accepte plus : **tout type créé est
   une prime**.
2. La catégorie d'un type existant **n'est jamais modifiée** (ni par le formulaire, ni par une
   migration). Un avantage en nature déjà créé reste valorisé ; indemnités et allocations restent
   versées. Les lignes de transmission déjà produites gardent leur catégorie.
3. L'énumération, la colonne `types_remuneration.categorie` et la règle de valorisation sont
   conservées telles quelles.
4. Formulaire réordonné : champs essentiels d'abord, options avancées repliées.

## Conséquences

- Plus aucun **nouvel avantage en nature** ne peut être créé depuis l'écran. Si le besoin revient
  (logement ou véhicule fourni), solution proposée, non codée : une case « Avantage en nature (non
  versé en argent) » dans les options avancées, qui fixe le traitement sans réintroduire de
  catégorie à choisir — à valider avant toute modification des règles.
- Aucune migration, aucun changement de paie ni de comptabilité.
