# Import de mise à jour en masse des véhicules

Workflow (page Véhicules, menu **Importer**) :

```text
Véhicules → Exporter pour mise à jour → modifier le fichier Excel
          → Mettre à jour les véhicules → aperçu → confirmer
```

Entièrement **séparé** de l'import flotte (`ImportFlotteController`/`ImportFlotteParser`, cf.
`docs/references-metier.md` pour le reste du domaine véhicules) : ce chemin ne crée **jamais**
de véhicule, de propriétaire ni d'équipe — il ne fait que corriger des véhicules déjà en base.
Table, modèle, policy, parseur et exécuteur sont propres à cette fonctionnalité
(`imports_vehicules_maj`, `ImportVehiculesMaj`, `ImportVehiculesMajPolicy`,
`ImportVehiculesMajParser`, `ImportVehiculesMajExecutor`) — aucun n'est partagé avec
l'import de création.

## Règles métier (IDs)

- **VEHMAJ-001** — Le fichier ne contient qu'une feuille "vehicules", une ligne par véhicule
  **déjà existant**, identifié par `vehicule_immatriculation` (comparée sur la forme normalisée
  — tirets/espaces/points/casse ignorés, cf. `Vehicule::normaliserImmatriculation()`), toujours
  scopée à l'organisation de l'utilisateur connecté.
- **VEHMAJ-002** — Une immatriculation introuvable dans l'organisation courante est une erreur
  bloquante pour **sa ligne uniquement** — jamais un fallback vers une création. Une
  immatriculation qui n'existe que dans une autre organisation est traitée à l'identique
  (isolation multi-tenant : la recherche est systématiquement scopée `organization_id`).
- **VEHMAJ-003** — Champs modifiables, liste fermée : `vehicule_site`,
  `capacite__<REFERENCE>` (une colonne dynamique par catégorie du catalogue produit ayant une
  `reference` — même convention que le gabarit flotte, cf. `CapaciteColonneResolver`),
  `vehicule_livraison_vente`, `vehicule_livraison_logistique`. Toute autre colonne présente dans
  le fichier (nom, marque, modèle, type, catégorie interne/partenaire, propriétaire...) est
  **silencieusement ignorée** — jamais lue, jamais écrite. L'exécuteur applique une whitelist
  explicite (`array_intersect_key`) au lieu d'un `fill()`/`update()` sur la ligne brute :
  impossible d'écraser une donnée sensible même en cas de bug de saisie du fichier.
- **VEHMAJ-004** — Une cellule vide/absente signifie **« ne pas modifier cette donnée »** : la
  valeur déjà en base est conservée telle quelle, jamais remise à `NULL`/`0`/`false`. C'est la
  seule différence de sémantique avec l'import flotte, où une cellule vide a un sens de création
  propre (ex : aucun usage par défaut sur un nouveau véhicule).
- **VEHMAJ-005** — Les usages acceptent `oui/non`, `yes/no`, `1/0`, `true/false`
  (`ImportValeurNormalizer::toBool()`, partagé avec l'import flotte). Une valeur non vide mais
  non reconnue bloque la ligne en erreur — jamais interprétée par défaut.
- **VEHMAJ-006** — Aperçu avant confirmation : chaque ligne expose la liste des changements
  réels (`avant → après`) par champ, le nombre de véhicules à mettre à jour, sans changement, et
  en erreur. Aucune écriture n'a lieu tant que l'utilisateur n'a pas confirmé (`estPret()` exige
  zéro ligne en erreur) — même mécanique aperçu/confirmation que l'import flotte et l'import
  produits (ré-analyse à l'instant T dans l'exécuteur, pour ne jamais rejouer un aperçu périmé).
- **VEHMAJ-007** — L'export "Exporter pour mise à jour"
  (`VehiculeController::exportMaj()` → `ExportVehiculesMajExport`) et le fichier attendu par
  l'import sont **strictement compatibles** : mêmes colonnes, même ordre. Un utilisateur
  télécharge l'export, modifie les valeurs autorisées et réimporte le fichier tel quel, sans
  réorganiser de colonne.
- **VEHMAJ-008** — Autorisation : `imports-vehicules-maj.create` (déposer un fichier, confirmer/
  relancer un import) et `imports-vehicules-maj.read` (consulter l'historique/l'aperçu). Comme
  pour l'import produits, `imports-vehicules-maj.read` seul ne permet jamais de déclencher une
  confirmation — `vehicules.update` ne gouverne pas non plus ce droit, qui reste exclusivement
  `imports-vehicules-maj.create` (cf. `ImportVehiculesMajPolicy::confirm()`).

## Fichiers clés

- `app/Services/ImportVehiculesMaj/ImportVehiculesMajParser.php` — analyse (lecture seule).
- `app/Services/ImportVehiculesMaj/ImportVehiculesMajExecutor.php` — application (whitelist,
  transactionnel, tout-ou-rien).
- `app/Services/ImportVehiculesMaj/ExportVehiculesMajExport.php` — export prérempli.
- `app/Http/Controllers/ImportVehiculesMajController.php` — cycle dépôt → aperçu → confirmation.
- `app/Services/ImportFlotte/Normalizers/CapaciteColonneResolver.php` et
  `ImportValeurNormalizer.php` — helpers génériques extraits d'`ImportFlotteParser`, réutilisés
  ici tels quels (comportement inchangé pour l'import flotte, couvert par `ImportFlotteTest`).
