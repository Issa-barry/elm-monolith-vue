<?php

namespace App\Http\Controllers;

use App\Models\PaieLigne;
use App\Models\PaiePaiement;
use App\Services\Comptabilite\PaiementSalaireService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Ancien écran Paie (RH) — conservé jusqu'au retrait prévu de ce circuit. Les paiements passent par
 * le même service que Comptabilité > Paiement salaire (verrou, reste relu sous verrou, agence).
 */
class PaiePaiementController extends Controller
{
    public function store(Request $request, PaieLigne $ligne, PaiementSalaireService $paiements): RedirectResponse
    {
        $this->authorize('pay', $ligne->periode);

        $data = $request->validate([
            'montant' => ['required', 'numeric', 'min:0.01'],
            'date_paiement' => ['required', 'date'],
            'mode_paiement' => ['required', 'string', 'in:especes,virement,cheque,mobile_money'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $paiements->payerLigne(
                $ligne,
                $request->user(),
                (float) $data['montant'],
                $data['mode_paiement'],
                $data['date_paiement'],
                $data['note'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['montant' => $e->getMessage()]);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return back()->withErrors(['comptabilisation' => "Paiement non enregistré : {$e->getMessage()}"]);
        }

        return back()->with('success', 'Paiement enregistré.');
    }

    public function destroy(Request $request, PaiePaiement $paiement, PaiementSalaireService $paiements): RedirectResponse
    {
        $this->authorize('pay', $paiement->ligne->periode);

        $paiements->annulerPaiement($paiement, $request->user());

        return back()->with('success', 'Paiement supprimé.');
    }
}
