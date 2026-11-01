<?php

namespace App\Http\Controllers\Comptabilite\Salaires;

use App\Http\Controllers\Controller;
use App\Models\PaiePeriode;
use App\Services\Comptabilite\ReprisePaieService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * « Reprendre la transmission RH » de Comptabilité > Paiement salaire (ADR 0041) : permission
 * `rh-paie.update`, toutes les agences. La version reprise est celle affichée à l'écran.
 */
class ReprendreTransmissionPaieController extends Controller
{
    public function __invoke(Request $request, PaiePeriode $paiePeriode, ReprisePaieService $reprise): RedirectResponse
    {
        $this->authorize('update', $paiePeriode);

        $data = $request->validate([
            'transmission_id' => ['required', 'string', 'max:26'],
        ]);

        try {
            $periode = $reprise->reprendre($paiePeriode, $data['transmission_id'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reprise' => $e->getMessage()]);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            report($e);

            return back()->withErrors(['reprise' => "Reprise non enregistrée : {$e->getMessage()}"]);
        }

        return back()->with('success', "Transmission RH reprise dans la paie de {$periode->labelPeriode()}.");
    }
}
