# Contrats de travail générés (PDF)

Décisions : [ADR 0044](adr/0044-contrat-de-travail-genere-modele-versions.md) (modèle par type,
instantané figé, versions) et [ADR 0048](adr/0048-contrat-genere-automatiquement-pdg-signataire.md)
(génération automatique, PDG signataire). Le salarié, son contrat, le PDG et l'agence apportent les
données, le **modèle** apporte le texte, le PDF est produit dans le modèle officiel partagé
([documents-pdf.md](documents-pdf.md)). **Aucune saisie au moment de la génération, aucun bouton
« Générer »** : le PDF est produit dès la création du contrat et remis à jour à chaque modification.

## 1. PDG de l'organisation — RH → Employés → fiche → « Désigner comme PDG »

- Le PDG est un **employé RH actif**, désigné depuis sa fiche (permission `rh-referentiels.gerer`,
  bouton masqué sans elle). Il est le représentant de l'employeur et le **signataire de tous les
  contrats** ; qualité imprimée : « Président-directeur général ». Ce n'est ni un rôle ni une
  permission informatique.
- **Un seul PDG par organisation** (`organizations.pdg_employe_id`). Désigner un autre employé
  remplace le précédent (confirmation, journal d'audit). Un employé ne peut pas être PDG de deux
  organisations ; un employé d'une autre organisation ou sorti ne peut pas être désigné.
- Le départ ou la suppression du PDG retire la désignation : un successeur est à désigner.
- **Sans PDG** : « Nouveau contrat » affiche le message et la création est refusée par le serveur
  (« … Veuillez désigner un PDG dans le module RH (RH → Employés → fiche de la personne → « Désigner
  comme PDG ») »). Aucun autre salarié n'est jamais pris à sa place. Les dossiers des salariés
  restent consultables, modifiables et importables ; l'import Excel met les dossiers à jour mais
  ne crée aucun contrat (avertissement sur la ligne, [import-dossiers-rh.md](import-dossiers-rh.md)
  IMPRH-006 bis).

## 2. Créer un contrat — RH → Contrats → Nouveau contrat

L'utilisateur saisit seulement ce que l'application ne connaît pas :

| Champ | Règle (vérifiée par le serveur) |
|---|---|
| Employé, type (CDI/CDD), date de début, date de fin (CDD) | inchangés |
| Salaire de base | avec `rh-remunerations.read` seulement (ignoré sinon) |
| **Période d'essai** | deux champs : un **nombre entier ≥ 0** (pas de lettres, pas de négatif, pas de décimale, ≤ 99) et **Semaines / Mois** (obligatoire si le nombre > 0). **0 = aucune** : le contrat indique « aucune » |
| **Heures de travail par semaine** | nombre **> 0 et ≤ 168**, deux décimales au plus (37,5 accepté) ; l'unité « heures/semaine » est affichée, jamais saisie |
| **Motif du CDD** | CDD seulement |

- Période d'essai, heures et motif du CDD sont **obligatoires à la création**, facultatifs en
  modification (contrats antérieurs : le PDF signale alors ce qui manque).
- Le statut n'est plus proposé à la création : un nouveau contrat est toujours actif.
- **« Créer le contrat »** enregistre le contrat **puis produit le PDF** (version 1, « à signer »)
  et ouvre la page du contrat. Le PDF est associé au contrat et à son organisation.

### Données reprises automatiquement

| Source | Variables |
|---|---|
| Organisation | `employeur.nom` |
| PDG désigné | `employeur.representant_nom`, `employeur.representant_qualite` (« Président-directeur général ») |
| Fiche du salarié | `salarie.civilite`, `salarie.nom_complet`, `salarie.prenom`, `salarie.nom`, `salarie.matricule`, `salarie.date_naissance`, `salarie.lieu_naissance`, `salarie.nationalite`, `salarie.adresse`, `salarie.date_embauche`, `poste.fonction`, `poste.service`, `poste.agence` |
| Agence du salarié | `poste.lieu_travail` (« Matoto, Conakry » : agence et sa ville) |
| Contrat | `contrat.type`, `contrat.date_debut`, `contrat.date_fin` (CDD), `contrat.salaire_base`, `contrat.periode_essai` (« 3 mois », « 2 semaines », « aucune »), `contrat.horaires` (« 40 heures par semaine »), `contrat.motif_cdd` (CDD) |
| Primes des salariés | `contrat.avantages` : **toutes** les affectations **validées** du salarié, en cours ou à venir (« Prime de transport (50 000 GNF par mois) », pourcentage du salaire de base, montant variable, avantage en nature avec sa valeur estimée, date de fin) ; « aucune prime ni aucun avantage » sinon. Jamais une proposition à valider, annulée, terminée ou d'un autre salarié |
| Document | `document.date` (date de génération), `document.lieu_signature` = **laissé en blanc** (« Fait à ……………………, ») : le lieu s'écrit à la main au moment de la signature |

### Information manquante

- Le contrat est **enregistré quand même** (la paie en dépend) ; le PDF ne l'est pas. Un message
  dit **quoi compléter et où** : « Informations manquantes pour le PDF : fonction (fiche du
  salarié : RH → Employés → Modifier) ; salaire de base mensuel (contrat : RH → Contrats →
  Modifier). » Même logique sans modèle actif (« un administrateur doit l'activer »).
- Le PDF est produit dès que la correction est faite : à l'enregistrement du contrat, à
  l'ouverture de sa page ou de son PDF par un gestionnaire, ou par « Mettre à jour le PDF ».
- Un échec technique est signalé (« Réessayez avec « Mettre à jour le PDF » ») ; le contrat n'est
  jamais créé deux fois.

## 3. Modifier un contrat, versions et signature

- Chaque PDF est une **version** (`documents_contrat`) : fichier sur le disque privé `contrats_rh`
  (`storage/app/private/contrats-rh`, jamais public), empreinte SHA-256 et **instantané figé**
  (textes rendus, valeurs, primes, révision du modèle). Une version ne change jamais.
- **Jamais de doublon** : une nouvelle version n'est créée que si son contenu diffère de la
  dernière (valeurs, parties et primes ; ni le numéro ni la date de génération), sous verrou du
  contrat — deux enregistrements simultanés ne produisent qu'une version.
- **Dernière version non signée** : à l'enregistrement d'une modification du contrat, à
  l'ouverture de la page du contrat ou de son PDF (gestionnaire), elle est remplacée par une
  version à jour si une information a changé (contrat, fiche du salarié, primes, PDG, modèle).
  L'ancienne passe « remplacée » et reste consultable. On n'ouvre jamais un ancien PDF d'un
  contrat modifié.
- **Dernière version signée** : conservée telle quelle, jamais remplacée. Si une **clause du
  contrat** est modifiée (type, dates, salaire, période d'essai, heures, motif, salarié), une
  nouvelle version **à faire signer** est préparée. Un changement hors contrat (fiche, primes, PDG)
  est seulement signalé (« La version N est signée … préparez une nouvelle version ») avec le
  bouton « Préparer une nouvelle version ».
- **Limite actuelle — pas d'avenant** : la nouvelle version après signature est un contrat
  **complet** à faire signer, pas un avenant (document court ne reprenant que la clause modifiée).
  La version signée et son PDF restent conservés. Un vrai processus d'avenant est probablement
  nécessaire ; il reste à décider (ADR 0048, « Hors périmètre »).
- **Marquer comme signé** : date de signature du papier (entre la date de génération et
  aujourd'hui), seulement sur la dernière version à signer. Générer le PDF ne vaut pas signature.
  La signature électronique et le dépôt du scan signé sont prévus en V2.
- Un contrat terminé ou rompu n'est plus mis à jour ; ses versions restent consultables.
- Nom du fichier : `CTR-{matricule}-{AAAAMMJJ début}-V{version}.pdf`. Si le fichier a disparu du
  disque, le PDF est reconstruit depuis l'instantané (incident journalisé).

## 4. Écrans

- **Page du contrat** (`GET /backoffice/contrats/{contrat}/document`, `contrats.document`) :
  « Voir le PDF », « Télécharger », « Modifier le contrat » ; bandeau d'état (vert « à jour »,
  orange « à mettre à jour » / « signé mais différent », rouge « à compléter » avec la raison) ;
  informations reprises (lecture seule) ; versions (voir, télécharger, marquer signé).
  « Mettre à jour le PDF » (`POST /backoffice/contrats/{contrat}/documents`) n'apparaît que s'il
  y a quelque chose à faire.
- **Liste des contrats** : colonne « Contrat de travail » (V2 · À signer, « PDF à préparer » pour
  un contrat actif sans PDF, ex. contrat importé). Menu ⋮ : **Voir le contrat (PDF)** et
  **Télécharger le PDF** (`GET /backoffice/contrats/{contrat}/pdf`, `?preview=1` = aperçu, 404
  sans version), **Fiche du contrat, signature**, **Préparer le PDF** (contrat actif sans PDF),
  Modifier, Supprimer.
- **Modèles de contrat** (Paramètres → RH, `rh-referentiels.gerer`) : inchangés (un modèle par
  type, brouillon → actif, révision, texte brut, variables `{{cle}}` refusées si inconnues ou
  réservées aux CDD). Toutes les variables sont désormais reprises automatiquement. Une variable
  utilisée et vide bloque le PDF : rien n'est jamais inventé.

## 5. Accès

| Action | Règle (serveur, `Rh\Contrats\VerifieAccesContrat`) |
|---|---|
| Consulter la page, voir/télécharger une version | `rh-contrats.read` + `rh-remunerations.read` (le PDF contient le salaire), même organisation, agence du salarié dans le périmètre de consultation (sauf vision toutes agences) |
| Mettre à jour le PDF, marquer signé | `rh-contrats.update` + `rh-remunerations.read`, même organisation, agence du salarié dans les agences de rattachement (sauf administrateur ou superviseur) |
| Créer / modifier le contrat (PDF produit automatiquement) | droits du contrat (`rh-contrats.create` / `.update`) et agence de rattachement ; la redirection mène à la page du contrat si l'utilisateur peut la consulter, sinon à la fiche du salarié |
| Désigner le PDG | `rh-referentiels.gerer`, même organisation, employé actif |
| Modèles de contrat | `rh-referentiels.gerer` |

- Organisation et agence vérifiées explicitement (le `Gate::before` du super administrateur
  court-circuite les policies). Le PDG d'une autre organisation n'est jamais repris.
- Les boutons sont masqués sans le droit réel (`peut_generer`, `peut_designer_pdg` calculés par
  le serveur).
- Données de paie : seuls le salaire de base et les primes validées figurent ; jamais de
  coordonnées de paiement ni de n° CNSS.

## 6. Tests

`tests/Feature/DocumentContratTest.php` (création avec PDF, PDG absent ou d'une autre organisation,
informations manquantes et où les compléter, validation période d'essai/heures, mise à jour sans
doublon, version signée conservée, primes validées, accès, isolation),
`tests/Feature/PdgOrganisationTest.php` (désignation, remplacement, permission, organisation,
départ et suppression), `tests/Feature/ModeleContratTest.php` (trame, variables, activation),
`tests/Feature/Documents/ModeleDocumentPdfTest.php` (rendu dans le modèle partagé, échappement).
