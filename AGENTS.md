# Instructions pour les agents — EAU LA MAMAN (`elm-monolithe`)

## Lecture obligatoire

**Avant toute modification d'interface (`.vue`, `.ts` du front) ou de règle métier, lire en
entier `docs/developpement/standards.md`.** C'est la référence unique des standards ; ce fichier
n'en est qu'un rappel. En cas de doute ou d'écart, le document fait foi.

Autres guides : `docs/README.md` (sommaire), `docs/developpement/tests.md`,
`docs/developpement/ci-cd.md`, et le document métier du domaine touché.

## Interdits (résumé)

Contrôlés en CI par `npm run lint:standards` (une violation bloque la pull request) :

1. Un statut affiché en pastille à fond coloré (`rounded-full` + `bg-*-50/100`) : utiliser
   `<StatusDot :status :label />`.
2. Des filtres faits main ou une recherche globale `search` : utiliser `DataFilters.vue`. Toute page
   de liste nouvelle ou modifiée : `ListPageActions` + `<DataFilters trigger-only>`.
3. Un `<Toast>` sans `position="top-right"` (aucune échappatoire).
4. Une icône de copie autre que `pi pi-clipboard`, `pi pi-clipboard` sans copie réelle, ou un bouton
   de copie sans `title="Cliquez pour copier …"`.
5. Un filtre `type: 'date-range'` ou une liste de périodes codée en dur : utiliser
   `type: 'period'` et le moteur `Periode` côté serveur (ADR 0057).

Vérifiés en revue de code ou par PHPUnit :

6. Calculer des bornes de période hors de `App\Support\Periodes\Periode`.
7. Une vue PDF de facture ou de bon avec son propre HTML/CSS : utiliser `<x-pdf.document>`.
8. Une action (créer, modifier, supprimer, valider…) visible sans `can('…')` correspondant
   exactement à la permission serveur.
9. Une règle métier contrôlée seulement dans le front : le serveur fait foi.
10. Une carte ou un bloc ajouté à un écran sans demande explicite.
11. Un mot de passe, jeton, clé ou numéro de téléphone réel dans le dépôt (il est public).

## Échappatoires

`ui-standard-ignore-file` (avec une courte justification) désactive les contrôles 1, 2 et 4 pour
un fichier. `periode-standard-ignore: <raison>` désactive le contrôle 5 (dérogation à documenter
dans `docs/periodes.md`). Rien ne désactive le contrôle 3.

## Avant de livrer

Lancer au minimum `npm run lint:standards` après une modification d'interface, puis les tests du
périmètre (section B6 du document des standards), et indiquer les commandes et leurs résultats
dans le compte rendu.
