<?php

namespace App\Http\Controllers;

use App\Enums\TypeVariablePaie;
use App\Models\PaieLigne;
use App\Models\PaieVariable;
use App\Services\PaieCalculService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaieVariableController extends Controller
{
    public function store(Request $request, PaieLigne $ligne, PaieCalculService $service): RedirectResponse
    {
        $this->authorize('update', $ligne->periode);

        if ($ligne->periode->statut->estVerrouille()) {
            return back()->withErrors(['statut' => 'La période est verrouillée.']);
        }

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(TypeVariablePaie::values())],
            'libelle' => ['required', 'string', 'max:150'],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $ligne->variables()->create($data);
        $service->calculerLigne($ligne);

        return back()->with('success', 'Variable ajoutée.');
    }

    public function update(Request $request, PaieVariable $variable, PaieCalculService $service): RedirectResponse
    {
        $ligne = $variable->ligne;
        $this->authorize('update', $ligne->periode);

        if ($ligne->periode->statut->estVerrouille()) {
            return back()->withErrors(['statut' => 'La période est verrouillée.']);
        }

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(TypeVariablePaie::values())],
            'libelle' => ['required', 'string', 'max:150'],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $variable->update($data);
        $service->calculerLigne($ligne);

        return back()->with('success', 'Variable mise à jour.');
    }

    public function destroy(PaieVariable $variable, PaieCalculService $service): RedirectResponse
    {
        $ligne = $variable->ligne;
        $this->authorize('update', $ligne->periode);

        if ($ligne->periode->statut->estVerrouille()) {
            return back()->withErrors(['statut' => 'La période est verrouillée.']);
        }

        $variable->delete();
        $service->calculerLigne($ligne);

        return back()->with('success', 'Variable supprimée.');
    }
}
