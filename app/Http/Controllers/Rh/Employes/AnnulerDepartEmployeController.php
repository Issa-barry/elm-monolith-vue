<?php

namespace App\Http\Controllers\Rh\Employes;

use App\Http\Controllers\Controller;
use App\Models\Employe;
use App\Services\Rh\DepartEmployeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** « Annuler le départ » (docs/rh-depart-salarie.md) : salarié et contrat rétablis, sauf paie figée. */
class AnnulerDepartEmployeController extends Controller
{
    public function __invoke(Request $request, Employe $employe, DepartEmployeService $departs): RedirectResponse
    {
        abort_unless(DepartEmployeService::peutGerer($request->user(), $employe), 403);

        $departs->annuler($employe);

        return back()->with('success', "Départ de {$employe->nom_complet} annulé : le salarié est de nouveau actif.");
    }
}
