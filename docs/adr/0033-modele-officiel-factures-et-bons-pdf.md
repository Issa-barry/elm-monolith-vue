# ADR 0033 — Modèle officiel partagé des factures et bons PDF

- **Date** : 2026-10-18
- **Statut** : accepté le 2026-10-18 — livré le 2026-10-18
- **Périmètre** : PDF de la facture d'achat, du bon de commande fournisseur et de la facture de
  vente — cf. [documents-pdf.md](../documents-pdf.md)

## Contexte

Chaque PDF de facture ou de bon portait son propre HTML et son propre CSS : la facture d'achat
reprenait le template « Invoice » d'Apollo en Poppins, le bon de commande une version DejaVu Sans
à 10,5 px, avec un logo traité différemment. Toute évolution de l'en-tête ou du pied devait être
recopiée document par document, et la facture de vente n'avait pas de PDF.

## Décision

1. **Un seul modèle** : composant Blade `x-pdf.document` et ses blocs `resources/views/pdf/modele/`
   (styles, en-tête, parties, lignes, résumé, pied). Visuel de référence : la facture d'achat.
2. **Données séparées de la présentation** : un constructeur par type produit un
   `App\Support\Documents\DocumentPdf` aux textes déjà formatés ; le modèle n'accède à aucun modèle
   Eloquent. Les différences métier passent par ses paramètres (mention, encadré, filigrane, pied)
   ou par le slot du composant — jamais par une copie du HTML ou du CSS.
3. **Rendu commun** : `DocumentPdfRenderer` (options DomPDF, pagination « Page X / Y », nom de
   fichier `{référence}.pdf`).
4. **Mentions propres** : « Ce document n’est pas l’original du fournisseur » reste propre à la
   facture d'achat. Le bon de commande conserve son snapshot de validation, son encadré et sa date
   de génération. La facture de vente reprend les montants tenus par le métier et n'affiche aucune
   TVA.
5. **Facture de vente téléchargeable** depuis `/backoffice/factures`, quel que soit son statut,
   avec les contrôles d'accès de la liste (permission de consultation des ventes, organisation,
   périmètre d'agences).
6. **Convention** : toute nouvelle facture ou tout nouveau bon PDF utilise ce modèle (CLAUDE.md,
   AGENTS.md).

## Amendement du 2026-10-19

7. **Pied de page officiel par organisation** (raison sociale, adresse, RCCM, NIF, contacts) :
   texte saisi dans Paramètres → Organisation, affiché centré sous un trait fin sur chaque page de
   tous les documents. Il n'est pas écrit dans le modèle : l'application est multi-organisation et
   ces mentions légales n'appartiennent qu'à une entreprise. Borné à 6 lignes de 150 caractères
   pour que la marge basse calculée suffise.
8. **Charte** : design d'origine de la facture d'achat conservé ; seul le nom de l'organisation
   prend les deux tons du logo « EAU LA MAMAN » (début bleu foncé, dernier mot bleu clair),
   centralisés dans `CharteDocument`. Une première version colorant titres et traits a été
   abandonnée le jour même à la demande du métier.
9. **Bloc CLIENT** de la facture de vente : uniquement si un client est associé, avec ses seules
   coordonnées renseignées (nom, adresse, téléphone, email), à côté du bloc VÉHICULE. RCCM, NIF et
   raison sociale ne sont pas affichés : la fiche client ne les porte pas.

## Conséquences

- Le bon de commande prend la typographie Poppins de la facture d'achat et le logo par défaut quand
  l'organisation n'en a pas.
- Les colonnes ont des largeurs fixes (46 / 14 / 19 / 21 %) : un texte long passe à la ligne, un
  montant très élevé peut tenir sur deux lignes.
- Les rapports et états (commissions, paiements, salaires, rapport d'activité) ne sont pas des
  factures ni des bons : ils gardent leur gabarit.
