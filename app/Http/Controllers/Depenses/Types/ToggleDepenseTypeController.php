<?php

namespace App\Http\Controllers\Depenses\Types;

use App\Http\Controllers\Controller;
use App\Models\DepenseType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ToggleDepenseTypeController extends Controller
{
    public function __invoke(Request $request, DepenseType $depense_type): RedirectResponse
    {
        $this->authorize('update', $depense_type);

        $depense_type->update(['is_active' => ! $depense_type->is_active]);

        $label = $depense_type->is_active ? 'activé' : 'désactivé';

        return back()->with('success', "Type « {$depense_type->libelle} » {$label}.");
    }
}
