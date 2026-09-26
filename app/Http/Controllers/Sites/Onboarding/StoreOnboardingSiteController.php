<?php

namespace App\Http\Controllers\Sites\Onboarding;

use App\Enums\SiteType;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\InstallationService;
use App\Support\AuthRedirects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Filet de sécurité pour une organisation sans aucun site — cf. ShowOnboardingSiteController
 * pour le contexte complet (le premier site est désormais créé dans /install, cette étape reste
 * utile pour une organisation historique ou toute anomalie de migration).
 */
class StoreOnboardingSiteController extends Controller
{
    public function __construct(private readonly InstallationService $service) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->authorize('create', Site::class);

        abort_if(
            ! AuthRedirects::needsOnboarding($user),
            403,
            'Cette organisation possède déjà au moins un site.'
        );

        $data = $request->validate([
            'type' => ['required', Rule::in(array_column(SiteType::cases(), 'value'))],
            'ville' => 'required|string|max:100',
            'quartier' => 'required|string|max:100',
        ], [
            'type.required' => 'Le type de site est obligatoire.',
            'ville.required' => 'La ville est obligatoire.',
            'quartier.required' => 'Le quartier est obligatoire.',
        ]);

        $this->service->creerPremierSite($user, $data);

        return redirect()->route('dashboard')->with('success', 'Site créé — bienvenue sur ELM.');
    }
}
