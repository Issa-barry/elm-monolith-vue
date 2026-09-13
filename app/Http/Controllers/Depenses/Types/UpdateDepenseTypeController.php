<?php

namespace App\Http\Controllers\Depenses\Types;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDepenseTypeRequest;
use App\Models\DepenseType;
use Illuminate\Http\RedirectResponse;

class UpdateDepenseTypeController extends Controller
{
    public function __invoke(UpdateDepenseTypeRequest $request, DepenseType $depense_type): RedirectResponse
    {
        $this->authorize('update', $depense_type);

        $depense_type->update($request->validated());

        return back()->with('success', 'Type de dépense mis à jour.');
    }
}
