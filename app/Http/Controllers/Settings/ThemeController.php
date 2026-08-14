<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateThemeRequest;
use App\Models\Parametre;
use App\Services\ThemePolicyService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ThemeController extends Controller
{
    public function __construct(private readonly ThemePolicyService $themePolicy) {}

    public function edit(): Response
    {
        abort_if(! auth()->user()->can('parametres.read'), 403);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403);

        return Inertia::render('settings/Theme', [
            'theme' => $this->themePolicy->sharedPayload($orgId),
        ]);
    }

    public function update(UpdateThemeRequest $request): RedirectResponse
    {
        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403);

        $validated = $request->validated();

        Parametre::setTheme($orgId, $validated['preset'], $validated['primary'], $validated['surface']);

        return back()->with('success', 'Thème mis à jour.');
    }
}
