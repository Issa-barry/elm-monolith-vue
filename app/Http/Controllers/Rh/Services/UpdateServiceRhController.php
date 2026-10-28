<?php

namespace App\Http\Controllers\Rh\Services;

use App\Http\Controllers\Controller;
use App\Models\ServiceRh;
use App\Services\Rh\ServiceRhService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateServiceRhController extends Controller
{
    public function __invoke(Request $request, ServiceRh $serviceRh, ServiceRhService $services): RedirectResponse
    {
        abort_unless($request->user()->can('rh-referentiels.gerer'), 403);
        // Contrôle explicite : le Gate::before du super administrateur ne vérifie pas l'organisation.
        abort_if($serviceRh->organization_id !== $request->user()->organization_id, 403);

        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:10'],
        ], ['libelle.required' => 'Le libellé est obligatoire.']);

        $services->modifier($serviceRh, $data);

        return back()->with('success', 'Service mis à jour.');
    }
}
