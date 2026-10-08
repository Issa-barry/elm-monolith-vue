<?php

namespace App\Http\Controllers\Settings\Achats;

use App\Http\Controllers\Controller;
use App\Models\RegleValidationRole;
use App\Models\Site;
use App\Support\Permissions\RoleVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateAchatParametrageController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('parametres.update'), 403);

        $orgId = auth()->user()->organization_id;
        $siteIds = Site::where('organization_id', $orgId)->pluck('id')->all();
        $roles = RoleVisibility::query($orgId)->pluck('name')->all();

        $validated = $request->validate([
            'config' => ['present', 'array'],
            'config.*.role_name' => ['required', 'string', Rule::in($roles)],
            'config.*.actif' => ['required', 'boolean'],
            'config.*.plafond_illimite' => ['required', 'boolean'],
            'config.*.plafond' => ['nullable', 'numeric', 'min:0'],
            'config.*.perimetre' => ['required', Rule::in(RegleValidationRole::PERIMETRES)],
            'config.*.sites' => ['array'],
            'config.*.sites.*' => ['string', Rule::in($siteIds)],
        ], [
            'config.*.role_name.in' => 'Rôle inconnu pour cette organisation.',
        ]);

        $erreurs = [];
        foreach ($validated['config'] as $i => $item) {
            if (! $item['actif']) {
                continue;
            }
            if ($item['perimetre'] === 'agences_selectionnees' && empty($item['sites'])) {
                $erreurs["config.{$i}.sites"] = 'Sélectionnez au moins une agence.';
            }
        }
        if ($erreurs !== []) {
            return back()->withErrors($erreurs);
        }

        DB::transaction(function () use ($validated, $orgId) {
            foreach ($validated['config'] as $item) {
                $cle = [
                    'organization_id' => $orgId,
                    'domaine' => RegleValidationRole::DOMAINE_ACHATS,
                    'role_name' => $item['role_name'],
                ];

                if (! $item['actif']) {
                    RegleValidationRole::where($cle)->delete();

                    continue;
                }

                RegleValidationRole::updateOrCreate($cle, [
                    'plafond_illimite' => $item['plafond_illimite'],
                    'plafond' => $item['plafond_illimite'] ? null : $item['plafond'],
                    'perimetre' => $item['perimetre'],
                    'sites' => $item['perimetre'] === 'agences_selectionnees'
                        ? array_values(array_unique($item['sites'] ?? []))
                        : null,
                ]);
            }
        });

        return back()->with('success', 'Plafonds de validation des achats mis à jour.');
    }
}
