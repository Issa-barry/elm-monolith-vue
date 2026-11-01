<?php

namespace App\Http\Controllers\Rh\Employes;

use App\Http\Controllers\Controller;
use App\Services\Rh\RechercheEmployeParTelephone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Téléphone du contact d'urgence (fiche salarié) : ce numéro appartient-il déjà à quelqu'un de
 * l'organisation ? Sert seulement à proposer de reprendre son nom — aucune fiche n'est créée ni
 * modifiée. Réservé à qui peut créer ou modifier un employé, dans son organisation.
 */
class RechercherContactUrgenceController extends Controller
{
    public function __invoke(Request $request, RechercheEmployeParTelephone $recherche): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->organization_id !== null && ($user->can('rh-employes.create') || $user->can('rh-employes.update')), 403);

        $data = $request->validate([
            'telephone' => ['required', 'string', 'max:30'],
            'indicatif' => ['nullable', 'string', 'max:6'],
        ]);

        $numero = RechercheEmployeParTelephone::normaliser($data['telephone'], $data['indicatif'] ?? null);
        if ($numero === null) {
            return response()->json(['statut' => 'invalide', 'correspondances' => []]);
        }

        $correspondances = $recherche->proprietairesDuNumero($user->organization_id, $numero['telephone']);

        return response()->json([
            'statut' => $correspondances === [] ? 'inconnu' : 'trouve',
            'telephone' => $numero['telephone'],
            'correspondances' => $correspondances,
        ]);
    }
}
