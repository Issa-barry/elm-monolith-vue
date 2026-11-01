<?php

namespace App\Http\Controllers\Rh\TypesRemuneration;

use App\Http\Controllers\Controller;
use App\Http\Requests\TypeRemunerationRequest;
use App\Models\TypeRemuneration;
use App\Services\Rh\TypeRemunerationService;
use Illuminate\Http\RedirectResponse;

class UpdateTypeRemunerationController extends Controller
{
    public function __invoke(TypeRemunerationRequest $request, TypeRemuneration $typeRemuneration, TypeRemunerationService $service): RedirectResponse
    {
        $this->authorize('update', $typeRemuneration);

        $service->modifier($typeRemuneration, $request->validated(), $request->user(), $request->boolean('valider'));

        return back()->with('success', 'Type mis à jour.');
    }
}
