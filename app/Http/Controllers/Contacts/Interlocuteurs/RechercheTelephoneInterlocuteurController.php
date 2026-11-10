<?php

namespace App\Http\Controllers\Contacts\Interlocuteurs;

use App\Http\Controllers\Controller;
use App\Models\EntrepriseTierce;
use App\Services\Contacts\RechercheTelephoneContact;
use App\Services\Rh\RechercheEmployeParTelephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** « Rechercher » de l'ajout d'un interlocuteur (ADR 0060, lot 4) — lecture seule. */
class RechercheTelephoneInterlocuteurController extends Controller
{
    use ControleAccesInterlocuteurs;

    public function __invoke(Request $request, EntrepriseTierce $entreprise, RechercheTelephoneContact $recherche): JsonResponse
    {
        $this->controlerAcces($request->user(), $entreprise);

        $data = $request->validate(['telephone' => ['required', 'string', 'max:30']]);
        $numero = RechercheEmployeParTelephone::normaliser($data['telephone']);
        if ($numero === null) {
            return response()->json([
                'statut' => 'invalide',
                'message' => 'Ce numéro n’est pas valide. Vérifiez le pays et le nombre de chiffres.',
            ]);
        }

        return response()->json($recherche->rechercherInterlocuteur($entreprise, $numero, $request->user()));
    }
}
