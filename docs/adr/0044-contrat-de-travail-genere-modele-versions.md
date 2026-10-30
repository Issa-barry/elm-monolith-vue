# ADR 0044 — Contrat de travail généré : modèle par type, instantané figé, versions

- **Date** : 2026-10-30
- **Statut** : accepté — V1 livrée le 2026-10-30 (non commitée)
- **Périmètre** : RH → Contrats, Paramètres → RH — cf. [rh-contrat-pdf.md](../rh-contrat-pdf.md),
  [documents-pdf.md](../documents-pdf.md)

## Contexte

Le 30/10/2026, RH → Contrats a reçu un « récapitulatif » PDF (commit 743ab859) : l'application ne
définissant aucune clause, le document ne pouvait pas servir de contrat. Le besoin est de produire
un vrai contrat à signer à partir d'un texte maîtrisé par l'entreprise, sans éditeur complexe ni
système RH parallèle, et sans qu'un contrat signé puisse changer après coup.

## Décision

1. **Un modèle de texte par type de contrat (CDI, CDD) et par organisation** (`modeles_contrat`),
   composé d'un intitulé et d'articles en **texte brut** avec des variables `{{…}}`. Pas de
   système de clauses indépendant, pas d'éditeur riche (le HTML saisi serait injecté dans le PDF) :
   un paragraphe par ligne vide, texte toujours échappé au rendu.
2. **Trame fournie, jamais validée par l'application** : douze articles structurants. Tant qu'un
   administrateur n'a pas relu et **activé** le modèle (statut `brouillon` → `actif`), aucune
   génération n'est possible. L'application ne prétend pas fournir un texte juridique.
3. **Variables contrôlées** (`VariablesContrat`) : catalogue fermé ; une variable inconnue, ou
   réservée aux CDD dans un modèle CDI, est refusée à l'enregistrement ; une variable utilisée et
   **vide bloque la génération** — rien n'est jamais inventé ni laissé en blanc. Les informations
   absentes de l'application (représentant de l'employeur, lieu de travail, période d'essai,
   horaires, motif du CDD, lieu de signature) se saisissent à la génération et sont figées avec la
   version, pas ajoutées au dossier RH.
4. **Primes et avantages** : uniquement des affectations **validées** du salarié (ADR 0039),
   cochées à la génération — le contrat ne promet rien que la paie ignore.
5. **Chaque génération crée une version** (`documents_contrat`) : PDF stocké sur un disque privé
   dédié (`contrats_rh`), empreinte SHA-256 et **instantané figé** des textes rendus et des valeurs.
   Le PDF ne se reconstruit jamais depuis les données courantes (seulement depuis l'instantané, si
   le fichier a disparu).
6. **Signature** : action manuelle « Marquer comme signé » (date du papier) sur la dernière version
   à signer ; la version est alors figée. Régénérer remplace une version à signer (statut
   `remplace`, fichier conservé) ; après signature, une génération crée une version de plus sans
   toucher à la version signée.
7. **Accès** : lecture = `rh-contrats.read` + organisation + périmètre de consultation ; génération
   et signature = `rh-contrats.update` + `rh-remunerations.read` + agence de rattachement ; modèles
   = `rh-referentiels.gerer` (permission existante, libellé élargi). Aucune nouvelle permission,
   donc aucune migration de droits.
8. Le **récapitulatif** du 30/10 est retiré : « Voir / Télécharger le PDF » ouvrent la dernière
   version générée.

## Conséquences

- Nouvelles tables seulement (migration `2026_10_30_140000`), aucune donnée existante touchée.
- Le modèle PDF partagé reçoit un bloc `articles` (et `intitule`) ; le bloc `sections` du
  récapitulatif est supprimé. Factures et bons inchangés.
- Le texte des contrats engage l'entreprise : sa validation juridique (droit du travail guinéen)
  reste hors de l'application.
- Reportés en V2 : import d'un modèle Word, éditeur avec mise en page, clauses personnalisées par
  salarié, signature électronique et dépôt du scan signé, circuit de validation.
