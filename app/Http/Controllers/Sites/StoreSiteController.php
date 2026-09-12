<?php

namespace App\Http\Controllers\Sites;

use App\Enums\SiteType;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\Sites\SiteFormSupport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreSiteController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $this->authorize('create', Site::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'type' => ['required', Rule::in(array_column(SiteType::cases(), 'value'))],
            'ville' => 'nullable|string|max:100',
            'quartier' => 'nullable|string|max:100',
            'telephone' => 'nullable|string|max:50',
        ], SiteFormSupport::messages());

        $data = SiteFormSupport::normalizeStrings($data);

        Site::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('sites.index')
            ->with('success', 'Site créé avec succès.');
    }
}
