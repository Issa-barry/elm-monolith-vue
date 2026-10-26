<?php

namespace App\Http\Controllers\Rh\Services;

use App\Http\Controllers\Controller;
use App\Models\ServiceRh;
use Inertia\Inertia;
use Inertia\Response;

/** Paramètres → RH → Services (permission `rh-referentiels.gerer`). */
class IndexServiceRhController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(auth()->user()->can('rh-referentiels.gerer'), 403);

        $services = ServiceRh::where('organization_id', auth()->user()->organization_id)
            ->withCount('employes')
            ->orderBy('libelle')
            ->get()
            ->map(fn (ServiceRh $s) => [
                'id' => $s->id,
                'libelle' => $s->libelle,
                'code' => $s->code,
                'is_active' => $s->is_active,
                'employes_count' => $s->employes_count,
            ]);

        return Inertia::render('ServicesRh/Index', ['services' => $services]);
    }
}
