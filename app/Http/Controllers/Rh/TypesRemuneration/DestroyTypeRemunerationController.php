<?php

namespace App\Http\Controllers\Rh\TypesRemuneration;

use App\Http\Controllers\Controller;
use App\Models\TypeRemuneration;
use App\Services\Rh\TypeRemunerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DestroyTypeRemunerationController extends Controller
{
    public function __invoke(Request $request, TypeRemuneration $typeRemuneration, TypeRemunerationService $service): RedirectResponse
    {
        $this->authorize('delete', $typeRemuneration);

        $service->supprimer($typeRemuneration, $request->user());

        return back()->with('success', 'Type supprimé.');
    }
}
