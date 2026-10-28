<?php

namespace App\Http\Controllers\Rh\TypesRemuneration;

use App\Http\Controllers\Controller;
use App\Http\Requests\TypeRemunerationRequest;
use App\Models\TypeRemuneration;
use App\Services\Rh\TypeRemunerationService;
use Illuminate\Http\RedirectResponse;

class StoreTypeRemunerationController extends Controller
{
    public function __invoke(TypeRemunerationRequest $request, TypeRemunerationService $service): RedirectResponse
    {
        $this->authorize('create', TypeRemuneration::class);

        $type = $service->creer($request->validated(), $request->user(), $request->boolean('valider'));

        return back()->with('success', $type->statut->estValide()
            ? 'Type créé et validé.'
            : 'Type créé. Il doit être validé avant de pouvoir être attribué.');
    }
}
