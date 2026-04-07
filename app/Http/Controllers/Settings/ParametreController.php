<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Parametre;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ParametreController extends Controller
{
    public function edit(): Response
    {
        abort_if(! auth()->user()->can('parametres.update'), 403);

        $orgId = auth()->user()->organization_id;

        $parametres = Parametre::where('organization_id', $orgId)
            ->orderBy('groupe')
            ->orderBy('cle')
            ->get()
            ->map(fn (Parametre $p) => [
                'id' => $p->id,
                'cle' => $p->cle,
                'valeur' => $p->valeur,
                'valeur_cast' => Parametre::castValue($p->valeur, $p->type),
                'type' => $p->type,
                'groupe' => $p->groupe,
                'description' => $p->description,
            ]);

        return Inertia::render('settings/Parametres', [
            'parametres' => $parametres,
        ]);
    }

    public function update(Request $request, Parametre $parametre): RedirectResponse
    {
        abort_if(! auth()->user()->can('parametres.update'), 403);
        abort_if($parametre->organization_id !== auth()->user()->organization_id, 403);

        $rules = match ($parametre->type) {
            Parametre::TYPE_INTEGER => ['valeur' => 'required|integer|min:0|max:9999999'],
            Parametre::TYPE_DECIMAL => ['valeur' => 'required|numeric|min:0|max:100|decimal:0,2'],
            Parametre::TYPE_BOOLEAN => ['valeur' => 'required|boolean'],
            Parametre::TYPE_JSON => ['valeur' => 'required|json'],
            default => ['valeur' => 'required|string|max:1000'],
        };

        $validated = $request->validate($rules);

        $parametre->update(['valeur' => (string) $validated['valeur']]);

        Parametre::clearCache(auth()->user()->organization_id);

        return back()->with('success', 'Paramètre mis à jour.');
    }
}
