<?php

namespace App\Http\Controllers\Settings\Parametres;

use App\Http\Controllers\Controller;
use App\Support\ExcelTemplateBuilder;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class DownloadTemplateParametreController extends Controller
{
    public function __invoke(string $template): HttpResponse
    {
        abort_if(! auth()->user()->can('parametres.update'), 403);

        [$filename, $sheets] = match ($template) {
            'produits' => [
                'template-produits.xls',
                [[
                    'name' => 'produits',
                    'headers' => [
                        'nom',
                        'code_barres',
                        // Nom du fournisseur (raison_sociale ou nom complet) — une seule
                        // colonne texte, pas les coordonnées complètes : le rattachement à un
                        // Prestataire existant (ou sa création) reste à faire par le futur
                        // parseur d'import, pas par ce template qui ne fait qu'annoncer les
                        // colonnes attendues.
                        'fournisseur',
                        'type',
                        'statut',
                        'prix_usine',
                        'prix_vente',
                        'prix_achat',
                        'cout',
                        'qte_stock',
                        // Vide = hérite du seuil par défaut de l'organisation ; valeur = seuil
                        // spécifique à ce produit (s'applique à toutes ses variantes/sites).
                        'seuil_alerte_stock',
                        'description',
                    ],
                ]],
            ],
            'sites' => [
                'template-sites.xls',
                [[
                    'name' => 'sites',
                    'headers' => [
                        'nom',
                        'type',
                        'ville',
                        'quartier',
                        'telephone',
                    ],
                ]],
            ],
            'users' => [
                'template-utilisateurs-sans-mot-de-passe.xls',
                [[
                    'name' => 'utilisateurs',
                    'headers' => [
                        'prenom',
                        'nom',
                        'email',
                        'telephone',
                        'code_pays',
                        'ville',
                        'adresse',
                        'role',
                        'site_id',
                        'is_active',
                    ],
                ]],
            ],
            'clients' => [
                'template-clients.xls',
                [[
                    'name' => 'clients',
                    'headers' => [
                        'nom',
                        'prenom',
                        'email',
                        'telephone',
                        'code_pays',
                        'ville',
                        'adresse',
                        'is_active',
                    ],
                ]],
            ],
            // Le template "vehicules-pack" (3 feuilles) a été remplacé par l'import
            // flotte dédié — voir ImportFlotteController::template() (route
            // imports-flotte.template), qui génère directement un .xlsx importable.
            default => [null, null],
        };

        abort_if($filename === null || $sheets === null, 404);

        $content = ExcelTemplateBuilder::build($sheets);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
