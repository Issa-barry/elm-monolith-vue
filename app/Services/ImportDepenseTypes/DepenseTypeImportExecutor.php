<?php

namespace App\Services\ImportDepenseTypes;

use App\Models\DepenseType;
use Illuminate\Support\Facades\DB;

/**
 * Crée réellement les types de dépense à partir d'un résultat d'analyse déjà
 * validé (DepenseTypeImportParser::analyser(), zéro ligne en erreur).
 * Tout-ou-rien : tout se joue dans une transaction unique — même philosophie
 * que SiteImportExecutor/ImportFlotteExecutor. Aucune mise à jour : chaque
 * ligne valide (statut "nouveau") est une pure création, le parser ayant déjà
 * rejeté tout doublon (cf. docblock de DepenseTypeImportParser).
 */
class DepenseTypeImportExecutor
{
    public function __construct(private readonly DepenseTypeImportParser $parser) {}

    /**
     * Ré-analyse le fichier à l'instant T (jamais l'aperçu déjà affiché à
     * l'utilisateur) pour éviter tout écart entre ce qui a été prévisualisé
     * et ce qui est enregistré — même garde-fou que les autres imports.
     */
    public function executer(string $absolutePath, string $orgId): array
    {
        $analyse = $this->parser->analyserFichier($absolutePath, $orgId);
        $lignes = $analyse['lignes'];

        $nbErreur = count(array_filter($lignes, fn ($l) => $l['statut'] === 'erreur'));
        if ($nbErreur > 0) {
            return ['succes' => false, 'analyse' => $analyse];
        }

        $compteurs = ['crees' => 0];

        DB::transaction(function () use ($lignes, $orgId, &$compteurs) {
            foreach ($lignes as $ligne) {
                $data = $ligne['data'];

                DepenseType::create([
                    'organization_id' => $orgId,
                    'code' => $data['code'],
                    'libelle' => $data['libelle'],
                    'description' => $data['description'],
                    'categorie' => $data['categorie'],
                    'commentaire_obligatoire' => $data['commentaire_obligatoire'],
                    'justificatif_obligatoire' => $data['justificatif_obligatoire'],
                    'is_active' => $data['is_active'],
                ]);

                $compteurs['crees']++;
            }
        });

        return ['succes' => true, 'analyse' => $analyse, 'compteurs' => $compteurs];
    }
}
