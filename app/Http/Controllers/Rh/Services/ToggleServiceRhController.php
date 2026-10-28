<?php

namespace App\Http\Controllers\Rh\Services;

use App\Http\Controllers\Controller;
use App\Models\ServiceRh;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Active ou désactive un service — jamais de suppression (historique des dossiers conservé). */
class ToggleServiceRhController extends Controller
{
    public function __invoke(Request $request, ServiceRh $serviceRh): RedirectResponse
    {
        abort_unless($request->user()->can('rh-referentiels.gerer'), 403);
        abort_if($serviceRh->organization_id !== $request->user()->organization_id, 403);

        $serviceRh->update(['is_active' => ! $serviceRh->is_active]);

        return back()->with('success', $serviceRh->is_active ? 'Service activé.' : 'Service désactivé.');
    }
}
