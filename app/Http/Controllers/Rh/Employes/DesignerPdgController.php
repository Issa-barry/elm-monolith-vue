<?php

namespace App\Http\Controllers\Rh\Employes;

use App\Http\Controllers\Controller;
use App\Models\Employe;
use App\Services\Rh\PdgService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** « Désigner comme PDG » (ADR 0048) : signataire de tous les contrats de l'organisation. */
class DesignerPdgController extends Controller
{
    public function __invoke(Request $request, Employe $employe, PdgService $pdg): RedirectResponse
    {
        abort_unless(PdgService::peutDesigner($request->user(), $employe), 403);
        $pdg->designer($employe, $request->user());

        return back()->with('success', "{$employe->nom_complet} est désormais le PDG de l’organisation : il signera les contrats de travail.");
    }
}
