<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Models\Personne;
use App\Services\Contacts\RechercheTelephoneContact;
use App\Services\Rh\RechercheEmployeParTelephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * « Rechercher » du parcours Nouveau contact (ADR 0060, lot 3). Lecture seule ; réservé à qui peut
 * créer un contact ; toujours dans l'organisation de l'acteur. Une fiche non visible ne renvoie
 * aucune donnée (statut « indisponible »).
 */
class RechercheTelephoneContactController extends Controller
{
    public function __invoke(Request $request, RechercheTelephoneContact $recherche): JsonResponse
    {
        $this->authorize('create', Personne::class);
        abort_if($request->user()->organization_id === null, 403);

        $data = $request->validate([
            'type' => ['required', Rule::in(['personne', 'entreprise'])],
            'telephone' => ['required', 'string', 'max:30'],
        ]);

        $numero = RechercheEmployeParTelephone::normaliser($data['telephone']);
        if ($numero === null) {
            return response()->json([
                'statut' => 'invalide',
                'message' => 'Ce numéro n’est pas valide. Vérifiez le pays et le nombre de chiffres.',
            ]);
        }

        return response()->json($recherche->rechercher($data['type'], $numero, $request->user()));
    }
}
