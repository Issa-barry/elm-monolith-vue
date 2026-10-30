# Récapitulatif PDF d'un contrat de travail

Depuis *RH → Contrats*, le menu ⋮ de chaque contrat propose :

- **Voir le contrat (PDF)** : ouvre le document dans un nouvel onglet (`?preview=1`, affichage
  `inline`) ; le lecteur du navigateur permet ensuite de l'imprimer ou de l'enregistrer ;
- **Télécharger le PDF** : même document, en pièce jointe (`CTR-{matricule}-{AAAAMMJJ}.pdf`).

Route : `GET /backoffice/contrats/{contrat}/pdf` (`contrats.pdf`), contrôleur
`Rh\Contrats\TelechargerContratController`, constructeur `App\Support\Documents\ContratDocument`,
vue `pdf/contrat_travail` (modèle officiel partagé, [documents-pdf.md](documents-pdf.md)).

## Ce que le document est — et n'est pas

C'est un **récapitulatif** établi à partir du dossier RH, pas un contrat de travail prêt à signer :
l'application ne définit aucune clause contractuelle. Le document le dit dans un encadré et en
pied de chaque page (« ne remplace pas le contrat de travail signé »).

Clauses absentes de l'application, listées dans l'encadré (`ContratDocument::CLAUSES_NON_GEREES`) :
période d'essai, durée et horaires de travail, lieu de travail, motif du recours au CDD,
représentant légal de l'employeur, convention collective.

## Contenu

| Bloc | Données |
|---|---|
| En-tête | Organisation (logo, nom en deux tons), agence actuelle du salarié, type, matricule, statut |
| Employeur | Nom de l'organisation ; représentant légal « non renseigné dans l'application » |
| Salarié | Civilité, prénom, nom, matricule ; naissance, nationalité et adresse si renseignées |
| Poste | Fonction, service, agence, date d'embauche (dossier RH actuel) |
| Contrat et rémunération | Type (CDI / CDD en toutes lettres), date de début, date de fin (« Sans date de fin » pour un CDI), salaire de base mensuel |
| Signatures | L'employeur (nom, qualité, date et cachet) ; le salarié (date, « Lu et approuvé ») |
| Pied | Pied de page officiel de l'organisation, mention du récapitulatif, date de génération, « Page X / Y » |

- Dates au format JJ/MM/AAAA, montants « 750 000 GNF / mois ».
- **Données de paie** : seul le salaire de base figure, et seulement avec `rh-remunerations.read`
  (sinon « Réservé aux profils autorisés »). Jamais de primes, d'avantages, de coordonnées de
  paiement ni de n° CNSS.
- Fonction, service et agence sont ceux du dossier **actuel** du salarié, y compris pour un
  contrat terminé.

## Informations manquantes

Le document n'invente jamais une valeur. S'il manque le salarié, son nom, la date de début, la date
de fin d'un CDD, le salaire de base ou la fonction, il est généré avec le filigrane **INCOMPLET**
et un encadré rouge « Document incomplet » qui liste les informations absentes. Sinon, filigrane
selon le statut : `TERMINÉ`, `ROMPU` (aucun pour un contrat actif).

## Accès

Mêmes règles que la liste des contrats, vérifiées par le serveur :

- permission `rh-contrats.read` (policy `ContratPolicy::view`) ;
- même organisation (contrôle explicite : le `Gate::before` du super administrateur court-circuite
  les policies) ;
- agence actuelle du salarié dans le périmètre de consultation (`SiteScopeService`), sauf vision de
  toutes les agences ; un salarié sans agence n'est accessible qu'avec cette vision.

Le contrat d'un salarié supprimé (soft delete) reste consultable.

Tests : `tests/Feature/ContratPdfTest.php` (accès, aperçu, téléchargement, salaire, contrat choisi),
`tests/Feature/Documents/ModeleDocumentPdfTest.php` (contenu CDI / CDD, incomplet, salaire masqué,
même modèle que les factures et bons).
