<?php

namespace App\Http\Controllers\Settings;

use App\Features\ModuleFeature;
use App\Http\Controllers\Controller;
use App\Services\ModuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class ModuleController extends Controller
{
    public function edit(): Response
    {
        abort_if(! auth()->user()->can('parametres.update'), 403);

        $org = auth()->user()->loadMissing('organization')->organization;
        abort_if(! $org, 403);

        $flags = ModuleService::allForOrg($org);
        $labels = ModuleFeature::labels();

        $modules = collect($flags)->map(fn ($active, $key) => [
            'key' => $key,
            'label' => $labels[$key] ?? $key,
            'active' => $active,
        ])->values();

        return Inertia::render('settings/Modules', [
            'modules' => $modules,
        ]);
    }

    public function toggle(Request $request): RedirectResponse
    {
        abort_if(! auth()->user()->can('parametres.update'), 403);

        $request->validate([
            'module' => ['required', 'string', 'in:' . implode(',', ModuleFeature::ALL)],
            'active' => ['required', 'boolean'],
        ]);

        $org = auth()->user()->loadMissing('organization')->organization;
        abort_if(! $org, 403);

        if ($request->boolean('active')) {
            Feature::for($org)->activate($request->input('module'));
        } else {
            Feature::for($org)->deactivate($request->input('module'));
        }

        return back()->with('success', 'Module mis à jour.');
    }
}
