<?php

namespace App\Http\Controllers\Comptabilite\Salaires;

use App\Http\Controllers\Controller;
use App\Models\PaiePeriode;
use App\Services\Comptabilite\ValidationPeriodePaieService;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/** « Valider la période » de Comptabilité > Paiement salaire (permission `rh-paie.validate`). */
class ValiderPeriodePaieController extends Controller
{
    public function __invoke(PaiePeriode $paiePeriode, ValidationPeriodePaieService $validation): RedirectResponse
    {
        $this->authorize('validate', $paiePeriode);

        try {
            $periode = $validation->valider($paiePeriode, auth()->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['periode' => $e->getMessage()]);
        }

        return back()->with('success', "Période {$periode->labelPeriode()} validée : les salaires peuvent être payés.");
    }
}
