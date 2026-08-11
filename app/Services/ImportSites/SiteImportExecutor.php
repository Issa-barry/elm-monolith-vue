<?php

namespace App\Services\ImportSites;

use App\Models\Site;
use App\Services\ImportFlotte\Normalizers\ImportTextNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Crée réellement les sites à partir d'un résultat d'analyse déjà validé
 * (SiteImportParser::analyser(), zéro ligne en erreur). Tout-ou-rien : tout
 * se joue dans une transaction unique — même philosophie que
 * ImportFlotteExecutor.
 *
 * Deux passes pour gérer un fichier contenant à la fois un site et ses
 * enfants (ex: ligne 2 = "Matoto", ligne 3 = "Cba" avec parent "Matoto") :
 *   1. créer tous les nouveaux sites (sans parent_id) ;
 *   2. rattacher les parents, une fois que tous les sites du fichier ont un id.
 *
 * Un site dont le nom correspond déjà à un site existant de l'organisation
 * n'est jamais recréé NI modifié — il est simplement réutilisé comme cible
 * possible d'un parent_id (cf. SiteImportParser, qui l'a déjà résolu comme
 * "existant" lors de l'analyse). Même philosophie que ImportFlotteExecutor,
 * qui ne touche jamais un véhicule déjà en base.
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

        $compteurs = ['crees' => 0, 'existants_ignores' => 0];

        DB::transaction(function () use ($lignes, $orgId, &$compteurs) {
            /** @var array<string, string> $idsParNom normalisé => id */
            $idsParNom = [];

            // ── Passe 1 : créer les nouveaux sites (sans parent_id) ──────────
            foreach ($lignes as $ligne) {
                $data = $ligne['data'];
                $nomNormalise = ImportTextNormalizer::normalize($data['nom']);

                if ($ligne['statut'] === 'existant') {
                    $idsParNom[$nomNormalise] = $data['existing_id'];
                    $compteurs['existants_ignores']++;

                    continue;
                }

                $site = Site::create([
                    'organization_id' => $orgId,
                    'nom' => $data['nom'],
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

            // ── Passe 2 : rattacher les parents ───────────────────────────────
            foreach ($lignes as $ligne) {
                $data = $ligne['data'];
                if ($ligne['statut'] !== 'nouveau' || $data['parent_nom'] === null) {
                    continue;
                }

                $parentId = $idsParNom[ImportTextNormalizer::normalize($data['parent_nom'])] ?? null;
                if ($parentId === null) {
                    // Garde-fou : déjà validé à l'analyse, ne devrait jamais arriver.
                    continue;
                }

                $nomNormalise = ImportTextNormalizer::normalize($data['nom']);
                Site::whereKey($idsParNom[$nomNormalise])->update(['parent_id' => $parentId]);
            }
        });

        return ['succes' => true, 'analyse' => $analyse, 'compteurs' => $compteurs];
    }
}
