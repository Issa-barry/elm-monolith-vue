<?php

namespace App\Http\Controllers\Ventes\Regularisation;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\Ventes\Regularisation\RegularisationVenteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Vérifie la confirmation exigée par l'organisation (code par e-mail ou confirmation simple) puis
 * exécute la régularisation (cf. RegularisationVenteService::confirmer()).
 */
class ConfirmerRegularisationVenteController extends Controller
{
    public function __invoke(Request $request, CommandeVente $commande_vente, RegularisationVenteService $service): RedirectResponse
    {
        $this->authorize('regulariserApresEncaissement', $commande_vente);

        $data = $request->validate(
            RecapitulatifRegularisationVenteController::reglesConfirmation() + [
                // Exigé ou non par le service selon le mode de l'organisation, jamais par l'écran.
                'code' => ['nullable', 'string', 'digits:6'],
            ],
            RecapitulatifRegularisationVenteController::messages() + [
                'code.digits' => 'Le code est composé de 6 chiffres.',
            ],
        );

        try {
            $regularisation = $service->confirmer(
                $commande_vente,
                $request->user(),
                $data['lignes'],
                $data['traitement_excedent'] ?? null,
                $data['motif'],
                $data['empreinte'],
                $data['code'] ?? null,
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            // Contrepassation ou recalcul impossible : la transaction a été annulée, rien n'a changé.
            return back()->withErrors(['regularisation' => "Régularisation impossible : {$e->getMessage()}"]);
        }

        $message = 'Vente régularisée : facture ramenée à '.number_format((float) $regularisation->montant_apres, 0, ',', ' ').' GNF.';
        if ((float) $regularisation->excedent > 0) {
            $message .= $regularisation->traitement_excedent === 'non_recu'
                ? ' Encaissement réduit de '.number_format((float) $regularisation->excedent, 0, ',', ' ').' GNF.'
                : ' Trop-perçu de '.number_format((float) $regularisation->excedent, 0, ',', ' ').' GNF à rembourser.';
        }

        return redirect()->route('ventes.show', $commande_vente)->with('success', $message);
    }
}
