# ADR 0048 — Contrat de travail généré automatiquement, PDG signataire désigné

- **Date** : 2026-11-01
- **Statut** : accepté (demande de l'utilisateur du 31/10/2026) — révise l'ADR 0044 (étape
  « Générer le contrat » et champs saisis à la génération)
- **Périmètre** : RH → Contrats, RH → Employés (fiche), Paramètres → RH → Modèles de contrat — cf.
  [rh-contrat-pdf.md](../rh-contrat-pdf.md)

## Contexte

L'ADR 0044 produisait le contrat en deux temps : créer le contrat, puis ouvrir « Générer le
contrat », remplir un second formulaire en texte libre (représentant de l'employeur, période
d'essai, horaires, lieu de travail, lieu de signature, motif du CDD), cocher les primes et cliquer
« Générer le PDF ». L'application est utilisée par des personnes peu habituées à l'informatique :
l'étape était oubliée, le texte libre donnait des valeurs incohérentes (« trois mois renouvelable »,
« 40h du lundi au samedi ») et le représentant de l'employeur était ressaisi à chaque contrat.

## Décision

1. **Un seul bouton : « Créer le contrat »**. Le PDF est produit tout de suite après
   l'enregistrement, puis remis à jour à chaque modification du contrat. Plus de formulaire de
   génération. L'action « Mettre à jour le PDF » ne sert qu'à réessayer après un échec ou à préparer
   une nouvelle version après une version signée.
2. **PDG désigné, signataire de tous les contrats** : un employé RH actif, désigné depuis sa fiche
   (« Désigner comme PDG », permission `rh-referentiels.gerer`), stocké dans
   `organizations.pdg_employe_id` (un seul par organisation ; index unique : un employé ne peut pas
   être PDG de deux organisations). Qualité imprimée : « Président-directeur général ». Ce n'est ni
   un rôle ni une permission. Remplacer le PDG est explicite et journalisé. Son départ ou sa
   suppression retire la désignation.
3. **Sans PDG, rien n'est produit** : la création d'un contrat est refusée avec un message qui dit
   où le désigner ; aucun autre salarié n'est jamais pris à sa place. Un PDG sorti, supprimé ou
   d'une autre organisation n'est pas reconnu.
4. **Conditions saisies une fois, à la création du contrat** (nouvelles colonnes de `contrats`) :
   période d'essai = nombre entier ≥ 0 (`periode_essai_duree`) + « Semaines » / « Mois »
   (`periode_essai_unite`), 0 = « aucune » ; heures de travail par semaine, nombre > 0 et ≤ 168,
   deux décimales au plus (`heures_hebdomadaires`) ; motif du CDD (`motif_cdd`). Obligatoires à la
   création (motif : CDD seulement), facultatifs en modification pour les contrats antérieurs.
   Validés côté serveur (lettres, négatifs, décimales de durée refusés).
5. **Tout le reste est repris automatiquement** : organisation, salarié, poste, salaire, PDG, lieu
   de travail (agence du salarié et sa ville), toutes les primes et tous les avantages validés en
   cours ou à venir (« aucune prime ni aucun avantage » sinon). Le **lieu de signature est laissé
   en blanc** (« Fait à …………, ») : il s'écrit à la main au moment de la signature.
6. **Information manquante** : le contrat est enregistré (la paie en dépend), le PDF ne l'est pas ;
   le message dit quoi compléter et sur quel écran. Le PDF est produit dès que la correction est
   faite (enregistrement du contrat, ouverture de sa page ou de son PDF, « Mettre à jour le PDF »).
7. **Jamais de doublon, jamais une version signée remplacée** : une nouvelle version n'est créée
   que si son contenu diffère de la dernière (empreinte des valeurs, parties et primes, hors numéro
   et date de génération), sous verrou du contrat. Une version non signée dépassée est remplacée
   (statut « remplacée », fichier conservé). Une version signée reste telle quelle ; une nouvelle
   version à faire signer n'est préparée que si une clause du contrat est modifiée
   (`DocumentContratService::CHAMPS_CONTRACTUELS`) ou à la demande (« Préparer une nouvelle
   version »). Un changement hors contrat (fiche du salarié, primes, PDG) après signature est
   seulement signalé.
8. **Générer n'est pas signer** : la signature reste l'action « Marquer comme signé » (papier).

## Conséquences

- Migration `2026_10_31_120000_add_pdg_et_conditions_contrat` : colonnes nouvelles et vides, aucune
  donnée existante modifiée (lancée en dev le 01/11/2026). Tant qu'aucun PDG n'est désigné, aucun
  contrat ne peut être créé, ni par l'écran ni par l'import : à désigner avant la mise en
  production.
- Les modèles existants restent valables : les variables `employeur.representant_*`,
  `poste.lieu_travail`, `contrat.periode_essai`, `contrat.horaires`, `contrat.motif_cdd` et
  `document.lieu_signature` sont désormais remplies automatiquement. Un texte de modèle qui
  supposait un lieu de signature écrit (« Fait à Conakry ») affichera des pointillés.
- Les versions générées avant cette décision restent consultables ; à la prochaine modification
  du contrat (ou ouverture de son PDF par un gestionnaire, si la dernière version n'est pas
  signée), une version au nouveau format est produite.
- **Import des dossiers RH** (décision du 01/11/2026) : l'import des dossiers reste possible sans
  PDG, mais il ne crée alors **aucun contrat** — la ligne porte un avertissement, pas une erreur,
  car une erreur bloquerait tout le fichier (IMPRH-006 bis). Avec un PDG, le contrat importé est
  créé sans période d'essai ni heures : son PDF est « à compléter » jusqu'à ce que le contrat soit
  complété dans RH → Contrats, puis produit automatiquement.

## Hors périmètre (décisions à prendre)

- **Avenant** : une clause modifiée après signature produit aujourd'hui une nouvelle version
  **complète** du contrat à faire signer ; la version signée et son PDF restent conservés et
  consultables. Ce n'est pas un avenant au sens juridique (document court ne reprenant que la
  clause modifiée, renvoyant au contrat initial). Un vrai processus d'avenant reste probablement
  nécessaire pour les modifications après signature (salaire, durée, poste) ; il n'est pas
  développé sans accord de l'utilisateur.
- **Renouvellement de la période d'essai** : non géré (durée initiale seulement).
- **Signature électronique** : non prévue (V2 de l'ADR 0044).
