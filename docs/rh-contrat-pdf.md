# Contrats de travail générés (PDF)

Décision : [ADR 0044](adr/0044-contrat-de-travail-genere-modele-versions.md). Le salarié et son
contrat apportent les données, le **modèle** apporte le texte, le générateur produit le PDF dans le
modèle officiel partagé ([documents-pdf.md](documents-pdf.md)). Il remplace le récapitulatif PDF
livré le 30/10/2026 (commit 743ab859).

## 1. Modèles de contrat — Paramètres → RH → Modèles de contrat

- **Un modèle par type** (CDI, CDD) et par organisation : intitulé + articles (titre, texte).
- Tant qu'aucun modèle n'est enregistré, l'écran présente la **trame de l'application** : douze
  articles (objet, nature, durée et prise d'effet, fonction, lieu de travail, période d'essai,
  rémunération, durée du travail/congés/absences, obligations, rupture, dispositions finales,
  signatures). C'est une **structure fonctionnelle, pas un texte juridique validé**.
- Statuts : **brouillon** (aucune génération possible) → **actif**. L'activation demande de
  confirmer que le texte a été relu et adapté au droit applicable ; « Repasser en brouillon »
  bloque à nouveau la génération. Un modèle actif modifié reste actif.
- Chaque enregistrement incrémente la **révision**, tracée dans chaque contrat généré.
- **Texte brut uniquement** (pas de HTML ni d'éditeur riche) : un paragraphe par ligne vide, un
  retour à la ligne simple est conservé. Le texte est toujours échappé dans le PDF.
- Permission : `rh-referentiels.gerer` (comme Fonctions et Services RH).

### Variables

Écrites `{{cle}}` (liste complète et cliquable dans l'éditeur, `VariablesContrat::CATALOGUE`) :

| Source | Variables |
|---|---|
| Dossier RH / contrat | `employeur.nom`, `salarie.civilite`, `salarie.nom_complet`, `salarie.prenom`, `salarie.nom`, `salarie.matricule`, `salarie.date_naissance`, `salarie.lieu_naissance`, `salarie.nationalite`, `salarie.adresse`, `salarie.date_embauche`, `poste.fonction`, `poste.service`, `poste.agence`, `contrat.type`, `contrat.date_debut`, `contrat.date_fin` (CDD), `contrat.salaire_base`, `document.date` |
| À compléter à la génération | `employeur.representant_nom`, `employeur.representant_qualite`, `poste.lieu_travail`, `contrat.periode_essai`, `contrat.horaires`, `contrat.motif_cdd` (CDD), `document.lieu_signature` |
| Primes cochées | `contrat.avantages` (« aucun » si rien n'est coché) |

- Une variable **inconnue**, ou réservée aux CDD dans un modèle CDI, est refusée à
  l'enregistrement.
- Une variable **utilisée et vide** bloque la génération (message « Informations manquantes : … ») :
  le document n'est jamais produit avec un trou ou une valeur inventée. Pour un article non
  applicable, écrire la valeur (« sans période d'essai ») ou retirer la variable du modèle.

## 2. Générer — RH → Contrats → ⋮ → Générer le contrat

Page `GET /backoffice/contrats/{contrat}/document` (`contrats.document`) :

1. Modèle actif du type du contrat (sinon message : « un administrateur doit l'activer »).
2. **Données reprises du dossier RH** (seulement celles que le modèle utilise), une valeur absente
   étant signalée en rouge.
3. **À compléter** : seulement les champs utilisés par le modèle. Proposés : les valeurs de la
   version précédente de ce contrat ; sinon le représentant du dernier contrat généré dans
   l'organisation, l'agence du salarié comme lieu de travail et sa ville comme lieu de signature.
4. **Primes et avantages** : cases à cocher parmi les affectations **validées** du salarié, en
   cours ou à venir (écran « Primes des salariés ») — jamais une proposition à valider, annulée,
   terminée ou d'un autre salarié (refus serveur). Texte repris : « Prime de transport (50 000 GNF
   par mois) », pourcentage du salaire de base, montant variable, avantage en nature (valeur
   estimée), date de fin éventuelle.
5. **Générer le PDF** → `POST /backoffice/contrats/{contrat}/documents`.

Refus : modèle non actif, contrat non actif (terminé ou rompu : les versions existantes restent
consultables), variable vide, prime non retenue.

## 3. Versions, signature et consultation

- Chaque génération crée une **version** (`documents_contrat`) : PDF stocké sur le disque privé
  `contrats_rh` (`storage/app/private/contrats-rh`, jamais public), empreinte SHA-256 et
  **instantané figé** (textes rendus, valeurs, champs saisis, primes, révision du modèle). Une
  modification ultérieure du dossier, du contrat ou du modèle ne change jamais une version.
- Statuts : **à signer** → **signé** ; une version à signer devient **remplacée** quand une
  nouvelle version est générée (« Régénérer »).
- **Marquer comme signé** : date de signature du papier (entre la date de génération et
  aujourd'hui), seulement sur la dernière version à signer. La version est alors figée ; une
  nouvelle génération crée une version de plus, la version signée reste conservée telle quelle.
  La signature électronique et le dépôt du scan signé sont prévus en V2.
- Liste des contrats : colonne « Contrat de travail » (V2 · À signer, Non généré). Menu ⋮ :
  **Voir le contrat (PDF)** (nouvel onglet) et **Télécharger le PDF** ouvrent la dernière version
  (`GET /backoffice/contrats/{contrat}/pdf`, `?preview=1` = aperçu, 404 sans version) ; **Versions,
  régénérer, signer** ouvre la page du contrat ; chaque version y est consultable
  (`contrats.documents.pdf`).
- Nom du fichier : `CTR-{matricule}-{AAAAMMJJ début}-V{version}.pdf`.
- Si le fichier a disparu du disque, le PDF est reconstruit depuis l'instantané (incident
  journalisé).

## 4. Accès

| Action | Règle (serveur, `Rh\Contrats\VerifieAccesContrat`) |
|---|---|
| Consulter la page, voir/télécharger une version | `rh-contrats.read` + `rh-remunerations.read` (le PDF contient le salaire, masqué dans la liste sans ce droit), même organisation, agence du salarié dans le périmètre de consultation (sauf vision toutes agences) |
| Générer, marquer signé | `rh-contrats.update` + `rh-remunerations.read` (le contrat contient le salaire), même organisation, agence du salarié dans les agences de rattachement (sauf administrateur ou superviseur) |
| Modèles de contrat | `rh-referentiels.gerer` |

- Organisation et agence vérifiées explicitement (le `Gate::before` du super administrateur
  court-circuite les policies). Salarié sans agence : génération réservée aux profils sans
  restriction d'agence.
- Les boutons sont masqués sans le droit réel (`peut_generer` calculé par le serveur, y compris
  l'agence de rattachement dans la liste).
- Données de paie : seuls le salaire de base et les primes **cochées** figurent ; jamais de
  coordonnées de paiement ni de n° CNSS.

## 5. Tests

`tests/Feature/DocumentContratTest.php` (génération, instantané figé, informations manquantes,
versions, signature, primes, accès), `tests/Feature/ModeleContratTest.php` (trame, variables,
activation, permission, isolation), `tests/Feature/Documents/ModeleDocumentPdfTest.php` (rendu
dans le modèle partagé, échappement des articles).
