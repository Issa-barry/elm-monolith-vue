# Fiche agent

Fiche de consultation d'un compte interne (`User`), au même modèle que les fiches Client et
Véhicule : en-tête `DetailHeader`, navigation latérale par onglets, cartes d'information.
L'édition reste sur le formulaire existant `users.edit`, ouvert par le bouton **Modifier**.

- Route : `GET /backoffice/users/{user}` (`users.show`, `User\ShowUserController`), module
  Utilisateurs. Accès : `UserPolicy::view` (`users.read`, même organisation ; super admin partout).
- Entrées : nom du compte dans **Comptes** (console et liste organisation), action « Voir la
  fiche » du menu de la ligne, fil d'Ariane et bouton Retour du formulaire d'édition.
- Onglet actif dans l'URL (`?tab=informations|situation|depenses`, `useUrlTab`).
  Un onglet non autorisé n'est jamais affiché ; un `?tab=` non autorisé retombe sur Informations.

## Onglets

| Onglet | Visible si | Contenu |
|---|---|---|
| Informations | toujours | nom complet, matricule, téléphone, e-mail, rôle, agences (défaut signalé), statut du compte, localisation ; bouton **Modifier** si `UserPolicy::update` |
| Situation | voir « Droits de la Situation » | tableau de bord de l'activité de l'agent |
| Dépenses | `depenses.read` + module Dépenses actif | dépenses saisies par l'agent |

Chaque onglet sensible est calculé côté serveur : la prop vaut `null` quand le consulteur n'y a pas
droit (`situation`, `lien_rapport`, `depenses`), et le frontend masque l'onglet.

**Pas d'onglet Mot de passe** (ADR 0015) : personne ne définit le mot de passe d'un autre compte,
ni sur la fiche, ni dans « Modifier le compte ». L'agent gère le sien dans Paramètres → Mot de passe ;
en cas d'oubli, il passe par le lien de réinitialisation. Le formulaire d'édition a une flèche de
retour vers la fiche à côté de son titre.

## Situation

Aucune règle propre : les chiffres sont ceux du **rapport d'activité** (docs/rapports.md,
ADR 0007), via `RapportActiviteService`, pour qu'un agent ait les mêmes chiffres sur sa fiche et
dans le rapport pour une même période et un même consulteur.

**Activité commerciale** — ventes **créées** par l'agent (`commandes_ventes.created_by`) :
- date d'une vente = création de sa **facture** ; CA = `montant_net` ;
- hors commandes annulées, annulées pour erreur de saisie, retournées, et factures annulées ;
- KPI : CA vendu, Encaissé sur ses ventes, Reste à payer, Nombre de ventes (= résumé
  `RapportActiviteService::ventes()`) ;
- graphiques sur exactement ces ventes, avec les agrégats de la Situation véhicule
  (`SituationVentesAgregats`) : produits vendus (quantité livrée, sinon demandée, par variante) et
  situation des paiements (Payé / Partiel / Impayé = **état des factures**, pas des moyens de paiement).

**Encaissements réalisés** — encaissements **saisis** par l'agent (`encaissements_ventes.created_by`),
datés par `date_encaissement`, quelle que soit la vente (résumé `RapportActiviteService::encaissements()`) :
montant, nombre, répartition par moyen (Espèces, Mobile Money par opérateur, Virement, Chèque) avec la
part du montant calculée côté serveur. À ne pas confondre avec l'« Encaissé sur ses ventes ».

**Période** : une seule pour l'onglet (`SituationPeriode`, mêmes choix que la fiche véhicule,
défaut « Toute la période »). « Toute la période » couvre tout l'historique
(`RapportPerimetre::debut()/fin()`) ; le rapport d'activité, lui, ne la propose pas.

**Voir le détail** : lien vers « Ma situation » (sa propre fiche) ou vers le rapport d'activité
filtré sur l'agent, avec les mêmes dates. Absent pour « Toute la période » (le rapport afficherait
un autre périmètre).

### Droits de la Situation

Résolus par `RapportPerimetreResolver::pourFicheAgent()` :
- **sa propre fiche** avec `rapports.read_own` : toute son activité (comme « Ma situation ») ;
- sinon `rapports.read` **et** l'agent fait partie des agents des agences du consulteur (même liste
  que le filtre Agent du rapport) ; les chiffres sont limités à ces agences. Administrateur : toute
  l'organisation de l'agent ;
- `users.read` seul ne donne **jamais** accès au CA ni aux encaissements d'un agent.

## Dépenses

Dépenses **saisies** par l'agent (`depenses.user_id`, renseigné à la création), tous statuts, sur
toute l'organisation (même périmètre que l'écran Dépenses). Synthèse : Validées (montant + nombre),
En attente de validation (statut Soumis), Nombre total. Tableau Date / Type / Catégorie / Montant /
Statut (`StatusDot`), 300 lignes les plus récentes, totaux sur toutes les dépenses ; chaque ligne
ouvre la dépense.

Non inclus (évolution possible) : les dépenses dont l'agent est **bénéficiaire** via une fiche
Employé (`beneficiaire_type = employe`, même `Personne`).

## Non disponible aujourd'hui

- Fonction / poste (seul le rôle existe sur le compte), date d'entrée.
- Filtre « créé par » sur l'écran Ventes : le détail passe par le rapport d'activité.
- Onglet Caisse (caisse dédiée de l'agent) : prévu avec la phase 4 trésorerie.

## Implémentation

- Backend : `User\ShowUserController`, `Agents\AgentSituationService`, `Agents\AgentDepensesService`,
  `RapportPerimetreResolver::pourFicheAgent()`, `Support\Situation\SituationVentesAgregats`.
- Frontend : `pages/Users/Show.vue`, `pages/Users/partials/Agent{Situation,Depenses}Tab.vue`,
  composants partagés `components/situation/*`, types `types/agent-fiche.ts`.
- Tests : `tests/Feature/Users/FicheAgentTest.php`, `pages/Users/__tests__/Show.spec.ts`,
  `pages/Users/partials/__tests__/AgentTabs.spec.ts`.
