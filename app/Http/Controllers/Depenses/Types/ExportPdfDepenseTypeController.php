<?php

namespace App\Http\Controllers\Depenses\Types;

use App\Enums\CategorieDepense;
use App\Http\Controllers\Controller;
use App\Models\DepenseType;
use App\Models\Organization;
use App\Support\Depenses\DepenseTypeFilterQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ExportPdfDepenseTypeController extends Controller
{
    public function __invoke(Request $request)
    {
        $this->authorize('create', DepenseType::class);

        $types = DepenseTypeFilterQuery::pour($request);
        $org = Organization::find(auth()->user()->organization_id);

        $pdf = Pdf::loadView('pdf.depense_types', [
            'types' => $types,
            'org_nom' => $org?->nom ?? '',
            'filtres' => $this->filtresLabel($request),
            'generated_at' => now()->format('d/m/Y à H:i'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('types-depense-'.now()->format('Y-m-d').'.pdf');
    }

    private function filtresLabel(Request $request): string
    {
        $parts = [];

        $categorie = (string) $request->input('categorie', '');
        if ($categorie !== '') {
            $cat = CategorieDepense::tryFrom($categorie);
            $parts[] = 'Concerné : '.($cat?->label() ?? $categorie);
        }

        $statut = (string) $request->input('statut', '');
        if ($statut === 'actif') {
            $parts[] = 'Statut : Actif';
        } elseif ($statut === 'inactif') {
            $parts[] = 'Statut : Inactif';
        }

        return $parts === [] ? 'Aucun filtre appliqué' : implode(' · ', $parts);
    }
}
