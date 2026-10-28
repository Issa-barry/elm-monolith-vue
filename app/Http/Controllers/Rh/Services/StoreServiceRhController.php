<?php

namespace App\Http\Controllers\Rh\Services;

use App\Http\Controllers\Controller;
use App\Services\Rh\ServiceRhService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreServiceRhController extends Controller
{
    public function __invoke(Request $request, ServiceRhService $services): RedirectResponse
    {
        abort_unless($request->user()->can('rh-referentiels.gerer'), 403);

        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403);

        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:10'],
        ], ['libelle.required' => 'Le libellé est obligatoire.']);

        $service = $services->creer($data, $orgId);

        return back()
            ->with('success', 'Service créé.')
            ->with('created_service_rh_id', $service->id);
    }
}
