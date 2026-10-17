<?php

namespace App\Http\Controllers\Depenses;

use App\Http\Controllers\Controller;
use App\Services\Depenses\CommandeCarburantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Commandes rattachables à une dépense de carburant (ADR 0031) — alimente la liste déroulante
 * filtrable des formulaires Créer/Modifier. Le rattachement est revérifié à l'enregistrement.
 */
class CommandesCarburantDepenseController extends Controller
{
    public function __invoke(Request $request, CommandeCarburantService $service): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('depenses.create') || $user->can('depenses.update'), 403);

        $orgId = $user->organization_id;

        $data = $request->validate([
            'vehicule_id' => ['required', 'ulid', Rule::exists('vehicules', 'id')->where('organization_id', $orgId)],
            'date_depense' => ['required', 'date'],
            'site_id' => ['nullable', 'ulid', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
        ]);

        return response()->json($service->candidats(
            $orgId,
            $data['vehicule_id'],
            Carbon::parse($data['date_depense']),
            $data['site_id'] ?? null,
        ));
    }
}
