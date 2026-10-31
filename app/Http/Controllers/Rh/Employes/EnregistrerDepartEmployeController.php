<?php

namespace App\Http\Controllers\Rh\Employes;

use App\Http\Controllers\Controller;
use App\Models\Employe;
use App\Services\Rh\DepartEmployeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** « Enregistrer le départ » (docs/rh-depart-salarie.md) : date de sortie effective et motif. */
class EnregistrerDepartEmployeController extends Controller
{
    public function __invoke(Request $request, Employe $employe, DepartEmployeService $departs): RedirectResponse
    {
        abort_unless(DepartEmployeService::peutGerer($request->user(), $employe), 403);

        $data = $request->validate([
            'date_sortie' => ['required', 'date_format:Y-m-d'],
            'motif_sortie' => ['required', 'string', 'max:255'],
        ], [
            'date_sortie.required' => 'La date de sortie est obligatoire.',
            'date_sortie.date_format' => 'Date de sortie invalide.',
            'motif_sortie.required' => 'Le motif de sortie est obligatoire.',
            'motif_sortie.max' => 'Le motif est limité à 255 caractères.',
        ]);

        $departs->enregistrer($employe, Carbon::createFromFormat('Y-m-d', $data['date_sortie']), $data['motif_sortie']);

        return back()->with('success', "Départ de {$employe->nom_complet} enregistré au ".Carbon::createFromFormat('Y-m-d', $data['date_sortie'])->format('d/m/Y').'.');
    }
}
