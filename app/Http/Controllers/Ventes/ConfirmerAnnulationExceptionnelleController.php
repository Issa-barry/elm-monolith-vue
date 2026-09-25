<?php

namespace App\Http\Controllers\Ventes;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\AnnulationExceptionnelleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ConfirmerAnnulationExceptionnelleController extends Controller
{
    /**
     * Vérifie la confirmation exigée par l'organisation (code reçu par e-mail ou confirmation
     * simple) puis exécute l'annulation exceptionnelle et ses régularisations (cf.
     * AnnulationExceptionnelleService::confirmer()).
     */
    public function __invoke(Request $request, CommandeVente $commande_vente, AnnulationExceptionnelleService $service): RedirectResponse
    {
        $this->authorize('annulerExceptionnel', $commande_vente);

        $data = $request->validate(
            DemanderCodeAnnulationExceptionnelleController::regles() + [
                // Facultatif ici : c'est le service qui exige le code selon le mode de confirmation
                // de l'organisation — jamais le frontend (qui pourrait simplement l'omettre).
                'code' => ['nullable', 'string', 'digits:6'],
            ],
            DemanderCodeAnnulationExceptionnelleController::messages() + [
                'code.digits' => 'Le code est composé de 6 chiffres.',
            ],
        );

        try {
            $service->confirmer($commande_vente, $request->user(), $data['motif'], $data['empreinte'], $data['code'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            // Contrepassation comptable impossible (ex : pièce non validée) : rien n'a été modifié,
            // la transaction a été annulée.
            return back()->withErrors(['annulation' => "Annulation impossible : {$e->getMessage()}"]);
        }

        return redirect()->route('ventes.show', $commande_vente)
            ->with('success', 'Commande annulée exceptionnellement : encaissements contrepassés, facture annulée, stock réintégré.');
    }
}
