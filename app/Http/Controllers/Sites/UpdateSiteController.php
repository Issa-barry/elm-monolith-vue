<?php

namespace App\Http\Controllers\Sites;

use App\Enums\SiteType;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\Sites\SiteFormSupport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UpdateSiteController extends Controller
{
    public function __invoke(Request $request, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'code' => [
                'required', 'string', 'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('sites', 'code')
                    ->where('organization_id', $orgId)
                    ->ignore($site->id),
            ],
            'type' => ['required', Rule::in(array_column(SiteType::cases(), 'value'))],
            'ville' => 'nullable|string|max:100',
            'quartier' => 'nullable|string|max:100',
            'telephone' => 'nullable|string|max:50',
        ], SiteFormSupport::messages());

        $data = SiteFormSupport::normalizeStrings($data);

        $site->update($data);

        return redirect()->route('sites.index')
            ->with('success', 'Site mis à jour avec succès.');
    }
}
