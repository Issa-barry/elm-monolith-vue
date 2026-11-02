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

        $data = $request->validate(
            PaiementSalaireService::regles() + ['date_paiement' => ['required', 'date']],
            PaiementSalaireService::messages(),
        );

        try {
            $paiements->payerLigne($ligne, $request->user(), $data);
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
