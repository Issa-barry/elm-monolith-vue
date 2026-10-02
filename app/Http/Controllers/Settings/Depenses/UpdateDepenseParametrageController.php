<?php

namespace App\Http\Controllers\Settings\Depenses;

use App\Http\Controllers\Controller;
use App\Models\DroitCreationDepense;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateDepenseParametrageController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->can('parametres.update'), 403);

        $orgId = auth()->user()->organization_id;
        $siteIds = Site::where('organization_id', $orgId)->pluck('id')->all();

        // Admin Entreprise n'a plus aucun accès automatique depuis le 2026-09-06 : le forçage
        // de peut_valider=true qui vivait ici rendait la case cochée par l'admin sans effet
        // réel (cf. audit rôles/permissions § "contournement automatique") — DroitCreationDepenseService
        // ne le bypasse plus nulle part (seul Super Admin reste illimité), donc sa ligne est
        // désormais traitée exactement comme celle de n'importe quel autre rôle ci-dessous.
        $validated = $request->validate([
            'config' => ['array'],
            'config.*.role_name' => ['required', 'string'],
            'config.*.is_actif' => ['required', 'boolean'],
            'config.*.peut_valider' => ['required', 'boolean'],
            'config.*.perimetre' => ['required', Rule::in(['toutes_agences', 'son_agence', 'agences_selectionnees'])],
            'config.*.sites' => ['array'],
            'config.*.sites.*' => ['string', Rule::in($siteIds)],
            // Obligatoire dès que peut_valider est actif. Super Admin et les rôles sans droit de
            // validation restent sans plafond (null).
            'config.*.plafond_validation' => ['nullable', 'numeric', 'min:0', 'required_if:config.*.peut_valider,true'],
        ], [
            'config.*.plafond_validation.required_if' => 'Le plafond de validation est obligatoire pour un rôle autorisé à valider.',
        ]);

        foreach ($validated['config'] ?? [] as $item) {
            $sites = $item['perimetre'] === 'agences_selectionnees'
                ? array_values(array_unique($item['sites'] ?? []))
                : null;

            DroitCreationDepense::updateOrCreate(
                ['organization_id' => $orgId, 'role_name' => $item['role_name']],
                [
                    'is_actif' => $item['is_actif'],
                    'perimetre' => $item['perimetre'],
                    'sites' => $sites,
                    'peut_valider' => $item['peut_valider'],
                    'plafond_validation' => $item['peut_valider'] ? $item['plafond_validation'] : null,
                ]
            );
        }

        return back()->with('success', 'Droits de validation mis à jour.');
    }
}
