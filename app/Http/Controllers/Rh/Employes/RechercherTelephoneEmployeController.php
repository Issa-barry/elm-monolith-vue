<?php

namespace App\Http\Controllers\Rh\Employes;

use App\Http\Controllers\Controller;
use App\Models\Employe;
use App\Services\Rh\RechercheEmployeParTelephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Rechercher » de Nouvel employé : le numéro existe-t-il déjà dans l'organisation ? Réservé à qui
 * peut créer un employé ; toujours dans l'organisation de l'acteur.
 */
class RechercherTelephoneEmployeController extends Controller
{
    public function __invoke(Request $request, RechercheEmployeParTelephone $recherche): JsonResponse
    {
        $this->authorize('create', Employe::class);
        abort_if($request->user()->organization_id === null, 403);

        $data = $request->validate([
            'telephone' => ['required', 'string', 'max:30'],
            'indicatif' => ['nullable', 'string', 'max:6'],
        ]);

        $numero = RechercheEmployeParTelephone::normaliser($data['telephone'], $data['indicatif'] ?? null);
        if ($numero === null) {
            return response()->json([
                'statut' => 'invalide',
                'message' => 'Ce numéro n’est pas valide. Vérifiez le pays et le nombre de chiffres.',
            ]);
        }

        return response()->json($recherche->rechercher($request->user(), $numero));
    }
}
