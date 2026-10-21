# Factures et bons PDF — modèle officiel partagé

Décision : [ADR 0033](adr/0033-modele-officiel-factures-et-bons-pdf.md). Toute facture ou tout bon
généré en PDF passe par **un seul modèle**, dont le visuel de référence est la facture d'achat
(template « Invoice » d'Apollo 6.2.0, Poppins). Modifier l'en-tête, le pied de page ou les styles
de ce modèle change tous les documents à la fois.

## Documents concernés

| Document | Route | Constructeur | Vue |
|---|---|---|---|
| Facture d'achat | `GET /backoffice/achats/factures/{facture}/pdf` (`achats.factures.pdf`, `?preview=1` = aperçu) | `FactureAchatDocument` | `pdf/facture_achat` |
| Bon de commande fournisseur | `GET /backoffice/achats/{achat}/pdf` (`achats.pdf`) | `BonCommandeAchatDocument` | `pdf/bon_commande_achat` |
| Facture de vente | `GET /backoffice/factures/{facture_vente}/pdf` (`factures.pdf`) | `FactureVenteDocument` | `pdf/facture_vente` |

Hors périmètre (ce ne sont ni des factures ni des bons) : rapports et états de commissions,
période et fiche de paiement, salaires, types de dépense, rapport d'activité, impression des
dépenses, ticket thermique du PDV.

## Architecture

1. **Préparation des données** — `app/Support/Documents/` :
   - `DocumentPdf` : objet de données prêt à afficher (titre, référence, identité, agence,
     métadonnées, parties, colonnes, lignes, totaux, notes, encadré, filigrane, mention, pied).
     Tous les textes y sont déjà formatés ; le modèle n'interroge aucun modèle Eloquent.
   - `IdentiteOrganisation` : nom, logo embarqué (logo personnalisé lisible, sinon logo par
     défaut), pied de page officiel, lignes d'agence (nom + localisation, ou ville/quartier).
   - `CharteDocument` : les deux tons du nom de l'organisation (voir « Charte »).
   - Un constructeur par type (`…Document::depuis()`), seul endroit où vivent les règles propres au
     document.
   - `DocumentPdfRenderer` : options DomPDF communes (A4 portrait, `fontHeightRatio` Poppins,
     pagination) et réponse HTTP (téléchargement, ou aperçu `inline`). Nom de fichier :
     `{référence}.pdf`.
   - `DocumentPdfPagination` : « Page X / Y » dessiné en fin de rendu (DomPDF ignore
     `counter(pages)`).
2. **Présentation** — composant `resources/views/components/pdf/document.blade.php`
   (`<x-pdf.document :document="$document" />`) et ses blocs `resources/views/pdf/modele/` :
   `styles`, `entete`, `parties`, `lignes`, `resume` (notes, totaux, encadré), `pied`. Le slot par
   défaut du composant accueille un complément propre à un document, affiché après les totaux.
3. **Vue par type** : une ligne qui délègue au composant. Elle ne contient ni HTML ni CSS.

## Charte

Le design d'origine de la facture d'achat (couleurs des textes, titres, tableaux, séparateurs, logo
par défaut) s'applique tel quel. **Seule exception** : le nom de l'organisation dans l'en-tête, en
deux tons repris du logo « EAU LA MAMAN » (`CharteDocument`) — le début du nom (« EAU LA ») en bleu
foncé `#2B5196`, son dernier mot (« MAMAN ») en bleu clair `#56ADE2` ; un nom d'un seul mot reste en
bleu foncé. Police, taille et position du nom inchangées. Le texte affiché est le nom saisi dans
Paramètres → Organisation : une faute de saisie (« EAU LA AMAN ») s'y corrige, jamais dans le modèle.
Valeurs relevées à l'œil sur le visuel fourni : à remplacer dans `CharteDocument` si les codes
officiels diffèrent.

## Pied de page officiel

- Propre à chaque organisation : champ « Pied de page des factures et bons » de Paramètres →
  Organisation (`organizations.pied_page_documents`, administrateurs). Aucune valeur par défaut :
  sans texte, pas de bloc.
- Une ligne par ligne saisie (lignes vides ignorées), **6 lignes de 150 caractères au plus** —
  limite contrôlée côté serveur, car la marge basse est calculée pour elle.
- Rendu centré sous un trait fin gris, au-dessus de la ligne mention / pagination, répété sur
  chaque page. Une ligne saisie trop longue pour la largeur de la page passe à la ligne dans le PDF.
  La marge basse grandit avec le nombre de lignes **rendues** (64 px sans pied, sinon
  64 + 17 + 14,2 px par ligne rendue, estimée à 95 caractères par ligne) : le contenu ne passe
  jamais sous le pied.

## Règles du modèle

- Tableaux plutôt que flexbox (DomPDF). `table-layout: fixed` et largeurs posées sur les cellules
  de la première ligne : un texte long, même sans espace, passe à la ligne au lieu d'élargir la
  page ; les colonnes standard (`DocumentPdf::colonnesStandard()`) font 46 / 14 / 19 / 21 %.
- L'en-tête du tableau des lignes se répète sur chaque page ; une ligne n'est jamais coupée entre
  deux pages ; le bloc notes + totaux et l'encadré restent entiers.
- Pied de page et filigrane sont répétés sur chaque page. Pied : mention propre au type, sinon
  « Organisation — référence », complément éventuel (date de génération), puis « Page X / Y ».
- Filigrane selon le statut réel du document, jamais décoratif.

## Spécificités par document

| Document | Mentions et données propres | Filigrane |
|---|---|---|
| Facture d'achat | « Ce document n’est pas l’original du fournisseur. » (uniquement ce document) ; n° fournisseur ou « Sans numéro » ; HT, TVA au taux de la facture, TTC. | `BROUILLON`, `ANNULÉE` |
| Bon de commande | Agence de livraison et agence payeuse ; fournisseur et agence du snapshot de validation (ACH-006) ; référence des lignes ; encadré de validation (règle de plafond appliquée), d'attente ou d'annulation (motif) ; date de génération. | `NON VALIDÉ`, `ANNULÉ` |
| Facture de vente | Bloc CLIENT (seulement si un client est associé : nom, adresse, téléphone, email — champs vides masqués ; la fiche client ne porte ni raison sociale, ni RCCM, ni NIF) sur la même ligne que le bloc VÉHICULE ; sans client ni véhicule, aucun bloc ; n° de commande s'il diffère ; statut. Montants et quantités repris du métier, jamais recalculés : quantité ayant servi au dernier calcul de `total_ligne` (livrée, sinon chargée, sinon préparée, sinon demandée), prix du mode de tarification figé, `total_ligne`, `montant_net` (montant brut affiché seulement s'il diffère), encaissé (acomptes compris, « dont acomptes »), remboursé, reste à payer (sauf facture annulée), trop-perçu à rendre (ADR 0019). **Aucune TVA** : la vente n'en porte pas. | `ANNULÉE` |

## Téléchargement d'une facture de vente

- Menu de chaque ligne de `/backoffice/factures` : « Télécharger la facture », immédiatement sous
  « Encaisser » quand cette action est affichée. Disponible quel que soit le statut, facture payée
  ou annulée comprise : il ne dépend pas de la possibilité d'encaisser. Vue mobile : bouton sur
  chaque carte.
- Serveur (`TelechargerFactureVenteController`) : permission `ventes.read` (policy de la commande),
  même organisation, et agence de la commande dans le périmètre de consultation
  (`SiteScopeService` : vision toutes agences, sinon agences rattachées). Organisation et agence
  vérifiées explicitement (le `Gate::before` du super administrateur court-circuite les policies).

## Ajouter une facture ou un bon

1. Créer `app/Support/Documents/XxxDocument.php` qui retourne un `DocumentPdf`.
2. Créer la vue `resources/views/pdf/xxx.blade.php` : `<x-pdf.document :document="$document" />`.
3. Contrôleur mono-action : autorisations existantes, puis
   `DocumentPdfRenderer::reponse(XxxDocument::depuis(...))`.
4. Ajouter le type à `ModeleDocumentPdfTest` (même modèle, propagation du bloc partagé).

Ne jamais recopier de HTML ni de CSS de facture dans une nouvelle vue : un besoin de présentation
nouveau s'ajoute au modèle (paramètre de `DocumentPdf` ou slot), pour tous les documents.
