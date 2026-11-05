# Contrat d'API — espace parrain

Pour la session Nuxt et tout client de l'espace client. Le schéma exact est dans
`docs/openapi/client.json` (`/docs/api` en local), qui fait foi sur le détail d'un champ. Règles métier :
[rattachement-comptes.md](rattachement-comptes.md), [ADR 0050](adr/0050-rattachement-manuel-comptes-parrain-consultant.md).

## Accès

| Cas | Réponse |
|---|---|
| Sans jeton | `401` |
| Compte actif sans parrain rattaché (client, agent du back-office...) | `404` |
| Compte sans parrain rattaché, ou détaché | `404` `{ "message": "Aucun parrain n'est rattaché à ce compte." }` |
| Filtre invalide | `422` |

- Toutes les routes exigent `auth:sanctum` seulement (front ouvert à tout compte actif, ADR 0055). Le parrain
  rattaché au compte décide de l'accès : sans lui, `404`. Un agent du back-office rattaché à un parrain y accède.
- **Aucune route ne reçoit d'identifiant de parrain** : il vient du compte authentifié, recalculé à chaque
  requête. Un paramètre `parrain_id` envoyé est ignoré.
- L'accès suit le rattachement posé sur la fiche (`user_id`) : automatique, y compris sur le numéro du compte, ou
  manuel au back-office (ADR 0056).
- Toutes les réponses sont en lecture seule. Aucune écriture n'existe dans cet espace.

## Détecter l'activité

`GET /api/auth/me` ajoute un champ, les autres ne changent pas :

```json
{ "context": { "organization_id": "…", "client_id": null, "proprietaire_id": null, "livreur_id": null, "parrain_id": "01J…" } }
```

`parrain_id` vaut `null` sans parrain rattaché. Il sert à afficher les menus, jamais de protection : l'API
refuse d'elle-même. Rattacher un compte déjà connecté prend effet au prochain `/me`.

## Routes

### `GET /api/v1/mobile/parrain/synthese`

```json
{
  "parrain": { "id": "01J…", "nom_complet": "Awa Bah", "telephone": "+224620000321", "is_active": true },
  "nb_vehicules": 2,
  "totaux": { "total_genere": 450000, "en_attente_periode": 0, "payable": 300000, "deja_paye": 150000, "reste_a_payer": 300000 }
}
```

| Champ | Sens |
|---|---|
| `total_genere` | Toutes les parts, sauf annulées. Égal à `en_attente_periode` + `payable` + `deja_paye` |
| `en_attente_periode` | Parts dont la période n'est pas encore validée : ni payables ni comptées dans le reste |
| `payable` | Restant dû sur les parts à payer ou partiellement payées |
| `deja_paye` | Total versé |
| `reste_a_payer` | Reste sur les parts passées par la validation de leur période, comme au back-office |

Ne recalculez aucun de ces montants côté client.

### `GET /api/v1/mobile/parrain/vehicules`

Véhicules parrainés **actuellement**. Un véhicule retiré ou remplacé sort de la liste, ses commissions restent.

```json
{
  "data": [{
    "id": "01J…", "nom_vehicule": "Camion 1", "immatriculation": "RC-123-A", "type_label": "Camion",
    "site_nom": "Matoto", "is_active": true,
    "parts": [{ "processus_label": "Ventes", "categorie_nom": "Sachet d'eau", "montant": 200, "a_definir": false, "depuis": "2026-10-01" }]
  }]
}
```

`montant` est en GNF par pack. `a_definir` vaut `true` tant qu'un responsable n'a pas décidé de la part (0 GNF non
confirmé, PARRAIN-022) : à afficher « À définir », pas « 0 GNF ». Pas de pagination.

### `GET /api/v1/mobile/parrain/commissions`

| Paramètre | Valeur |
|---|---|
| `vehicule_id` | Véhicule de l'opération. Sans part de ce parrain, la liste est vide |
| `statut` | `creee`, `impaye`, `partiel` ou `paye` |
| `date_debut`, `date_fin` | Date de l'opération, `AAAA-MM-JJ` |
| `per_page`, `page` | 20 par défaut, 100 au plus |

```json
{
  "data": [{
    "id": "01J…", "date": "2026-10-10", "reference": "CMD-000123", "processus_label": "Ventes",
    "vehicule": { "id": "01J…", "nom_vehicule": "Camion 1", "immatriculation": "RC-123-A" },
    "montant_unitaire": 200, "montant_a_payer": 20000, "montant_verse": 5000, "montant_restant": 15000,
    "statut": "partiel", "statut_label": "Partiellement payée"
  }],
  "links": {}, "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 },
  "resume": { "total_genere": 20000, "en_attente_periode": 0, "payable": 15000, "deja_paye": 5000, "reste_a_payer": 15000 },
  "filters": { "vehicule_id": null, "statut": null, "date_debut": null, "date_fin": null }
}
```

- Tri du plus récent au plus ancien, sans les commissions annulées.
- `resume` porte sur **tous les résultats filtrés**, pas sur la seule page.
- `montant_a_payer` reprend un éventuel ajustement d'un responsable.
- `reference`, `vehicule`, `date` et `processus_label` peuvent valoir `null` si l'opération a été supprimée.
- Le statut `creee` signifie « en attente de validation de la période » : le libellé brut « Créée » n'est pas destiné
  au parrain.

### `GET /api/v1/mobile/parrain/paiements`

Paramètres : `date_debut`, `date_fin` (date du paiement), `per_page`, `page`.

```json
{
  "data": [{
    "id": "01J…", "date": "2026-10-16", "montant": 5000, "mode_paiement": "especes", "mode_paiement_label": "Espèces",
    "reference_paiement": null, "fiche_reference": "FP-000123", "periode_reference": "PAY-202610-P1-PAR"
  }],
  "links": {}, "meta": {}, "filters": { "date_debut": null, "date_fin": null }
}
```

## Données jamais exposées

Client de la commande, montant de la commande, parts des livreurs, du propriétaire ou du site, équipe du véhicule,
compte de trésorerie, auteur et note d'un paiement.

## Cas à gérer

- **`404` en cours de session** : le compte vient d'être détaché. Le dire simplement, recharger `/me` et ne pas
  déconnecter.
- **Parrain désactivé** (`parrain.is_active` à `false`) : il garde l'accès à son historique.
- **Parrain seul** (`client_id`, `proprietaire_id` et `livreur_id` à `null`) : `GET /v1/mobile/dashboard` ne renvoie rien
  d'utile pour lui, son accueil est la synthèse ci-dessus.
- **Cumul** : un compte peut être aussi propriétaire, livreur ou client. Les données de chaque activité restent lues
  sur leurs propres routes et ne s'additionnent pas.
