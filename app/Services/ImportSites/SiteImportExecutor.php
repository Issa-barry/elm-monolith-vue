<?php

namespace App\Services\ImportSites;

use App\Models\Site;
use App\Services\ImportFlotte\Normalizers\ImportTextNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Crée ou met à jour réellement les sites à partir d'un résultat d'analyse
 * déjà validé (SiteImportParser::analyser(), zéro ligne en erreur). Tout-ou-
 * rien : tout se joue dans une transaction unique — même philosophie que
 * ImportFlotteExecutor.
 *
 * Deux passes pour gérer un fichier contenant à la fois un site et ses
 * enfants (ex: ligne 2 = "Matoto", ligne 3 = "Cba" avec parent "Matoto") :
 *   1. créer les nouveaux sites (sans parent_id) et mettre à jour ceux
 *      rapprochés par code ;
 *   2. rattacher/mettre à jour les parents, une fois que tous les sites du
 *      fichier ont un id.
 *
 * Un site rapproché par NOM (pas de `code_facultatif` dans la ligne) n'est
 * jamais recréé NI modifié — il est simplement réutilisé comme cible
 * possible d'un parent_id (statut "existant", cf. SiteImportParser). Même
 * philosophie que ImportFlotteExecutor, qui ne touche jamais un véhicule déjà
 * en base.
 *
 * Un site rapproché par CODE (statut "mise_a_jour") est en revanche
 * réellement mis à jour — c'est la raison d'être du code comme identifiant
 * métier de réimport (cf. docblock de SiteImportParser). Seuls les champs
 * réellement présents dans le fichier sont modifiés ; une colonne facultative
 * vide ne réinitialise jamais une valeur déjà enregistrée (`*_fournie` /
 * `parent_fourni` calculés par le parser pilotent cette omission).
 */
class SiteImportExecutor
{
    public function __construct(private readonly SiteImportParser $parser) {}

    /**
     * Ré-analyse le fichier à l'instant T (jamais l'aperçu déjà affiché à
     * l'utilisateur) pour éviter tout écart entre ce qui a été prévisualisé
     * et ce qui est enregistré — même garde-fou que ImportFlotteExecutor.
     */
    public function executer(string $absolutePath, string $orgId): array
    {
        $analyse = $this->parser->analyserFichier($absolutePath, $orgId);
        $lignes = $analyse['lignes'];

        $nbErreur = count(array_filter($lignes, fn ($l) => $l['statut'] === 'erreur'));
        if ($nbErreur > 0) {
            return ['succes' => false, 'analyse' => $analyse];
        }

        $compteurs = ['crees' => 0, 'mis_a_jour' => 0, 'existants_ignores' => 0];

        DB::transaction(function () use ($lignes, $orgId, &$compteurs) {
            /** @var array<string, string> $idsParNom normalisé => id */
            $idsParNom = [];

            // ── Passe 1 : créer / mettre à jour (sans toucher parent_id) ──────
            foreach ($lignes as $ligne) {
                $data = $ligne['data'];
                $nomNormalise = ImportTextNormalizer::normalize($data['nom']);

                if ($ligne['statut'] === 'existant') {
                    $idsParNom[$nomNormalise] = $data['existing_id'];
                    $compteurs['existants_ignores']++;

                    continue;
                }

                if ($ligne['statut'] === 'mise_a_jour') {
                    $misAJour = [
                        'nom' => $data['nom'],
                        'type' => $data['type'],
                        'ville' => $data['ville'],
                        'quartier' => $data['quartier'],
                        'telephone' => $data['telephone'],
                    ];
                    // Une colonne facultative vide ne doit jamais effacer une
                    // valeur déjà enregistrée : on n'inclut la clé dans la
                    // mise à jour que si le fichier la renseignait réellement.
                    if ($data['description_fournie']) {
                        $misAJour['description'] = $data['description'];
                    }
                    if ($data['longitude_fournie']) {
                        $misAJour['longitude'] = $data['longitude'];
                    }
                    if ($data['latitude_fournie']) {
                        $misAJour['latitude'] = $data['latitude'];
                    }

                    Site::whereKey($data['existing_id'])->update($misAJour);

                    $idsParNom[$nomNormalise] = $data['existing_id'];
                    $compteurs['mis_a_jour']++;

                    continue;
                }

                $site = Site::create([
                    'organization_id' => $orgId,
                    'nom' => $data['nom'],
                    'code' => $data['code'], // null => auto-généré par Site::boot()
                    'type' => $data['type'],
                    'ville' => $data['ville'],
                    'quartier' => $data['quartier'],
                    'telephone' => $data['telephone'],
                    'description' => $data['description'],
                    'longitude' => $data['longitude'],
                    'latitude' => $data['latitude'],
                ]);

                $idsParNom[$nomNormalise] = $site->id;
                $compteurs['crees']++;
            }

            // ── Passe 2 : rattacher/mettre à jour les parents ─────────────────
            foreach ($lignes as $ligne) {
                $data = $ligne['data'];
                $nomNormalise = ImportTextNormalizer::normalize($data['nom']);

                if ($ligne['statut'] === 'nouveau') {
                    if ($data['parent_nom'] === null) {
                        continue;
                    }
                    // Le parent peut déjà exister en base (résolu directement par
                    // le parser via `parent_existing_id`, y compris par code) ou
                    // être un autre site créé dans ce même fichier (résolu ici
                    // par son nom, une fois qu'il a un id — cf. docblock).
                    $parentId = $data['parent_existing_id'] ?? ($idsParNom[ImportTextNormalizer::normalize($data['parent_nom'])] ?? null);
                    if ($parentId === null) {
                        // Garde-fou : déjà validé à l'analyse, ne devrait jamais arriver.
                        continue;
                    }
                    Site::whereKey($idsParNom[$nomNormalise])->update(['parent_id' => $parentId]);

                    continue;
                }

                if ($ligne['statut'] === 'mise_a_jour' && $data['parent_fourni']) {
                    if ($data['parent_nom'] === null) {
                        // Garde-fou : déjà validé à l'analyse, ne devrait jamais arriver.
                        continue;
                    }
                    $parentId = $data['parent_existing_id'] ?? ($idsParNom[ImportTextNormalizer::normalize($data['parent_nom'])] ?? null);
                    if ($parentId === null) {
                        continue;
                    }
                    Site::whereKey($data['existing_id'])->update(['parent_id' => $parentId]);
                }
            }
        });

        return ['succes' => true, 'analyse' => $analyse, 'compteurs' => $compteurs];
    }
}
