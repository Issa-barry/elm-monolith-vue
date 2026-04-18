<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Parametre;
use App\Support\ExcelTemplateBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ParametreController extends Controller
{
    public function edit(): Response
    {
        abort_if(! auth()->user()->can('parametres.read'), 403);

        $orgId = auth()->user()->organization_id;

        $parametres = Parametre::where('organization_id', $orgId)
            ->where('groupe', '!=', Parametre::GROUPE_VENTES)
            ->orderBy('groupe')
            ->orderBy('cle')
            ->get()
            ->map(fn (Parametre $p) => [
                'id' => $p->id,
                'cle' => $p->cle,
                'valeur' => $p->valeur,
                'valeur_cast' => Parametre::castValue($p->valeur, $p->type),
                'type' => $p->type,
                'groupe' => $p->groupe,
                'description' => $p->description,
            ]);

        return Inertia::render('settings/Parametres', [
            'parametres' => $parametres,
        ]);
    }

    public function update(Request $request, Parametre $parametre): RedirectResponse
    {
        abort_if(! auth()->user()->can('parametres.update'), 403);
        abort_if($parametre->organization_id !== auth()->user()->organization_id, 403);

        $rules = match ($parametre->type) {
            Parametre::TYPE_INTEGER => ['valeur' => 'required|integer|min:0|max:9999999'],
            Parametre::TYPE_DECIMAL => ['valeur' => 'required|numeric|min:0|max:100|decimal:0,2'],
            Parametre::TYPE_BOOLEAN => ['valeur' => 'required|boolean'],
            Parametre::TYPE_JSON => ['valeur' => 'required|json'],
            default => ['valeur' => 'required|string|max:1000'],
        };

        $validated = $request->validate($rules);

        $parametre->update(['valeur' => (string) $validated['valeur']]);

        Parametre::clearCache(auth()->user()->organization_id);

        return back()->with('success', 'Paramètre mis à jour.');
    }

    public function downloadTemplate(string $template): HttpResponse
    {
        abort_if(! auth()->user()->can('parametres.update'), 403);

        [$filename, $sheets] = match ($template) {
            'produits' => [
                'template-produits.xls',
                [[
                    'name' => 'produits',
                    'headers' => [
                        'nom',
                        'code_fournisseur',
                        'type',
                        'statut',
                        'prix_usine',
                        'prix_vente',
                        'prix_achat',
                        'cout',
                        'qte_stock',
                        'seuil_alerte_stock',
                        'description',
                        'is_critique',
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
            'vehicules-pack' => [
                'template-vehicules-proprietaires-livreurs.xls',
                [
                    [
                        'name' => 'proprietaires',
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
                    ],
                    [
                        'name' => 'livreurs',
                        'headers' => [
                            'nom',
                            'prenom',
                            'telephone',
                        ],
                    ],
                    [
                        'name' => 'vehicules',
                        'headers' => [
                            'nom_vehicule',
                            'immatriculation',
                            'type_vehicule',
                            'capacite_packs',
                            'categorie',
                            'proprietaire_id',
                            'pris_en_charge_par_usine',
                            'is_active',
                        ],
                    ],
                ],
            ],
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
