<?php

namespace App\Http\Controllers;

use App\Enums\SiteType;
use App\Models\Site;
use App\Services\InstallationService;
use App\Support\AuthRedirects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Filet de sécurité pour une organisation sans aucun site — le premier site est désormais créé
 * DANS /install ou `app:install` (cf. InstallationService::install()/creerSite()), donc une
 * installation neuve n'atterrit plus jamais ici. Reste utile pour une organisation historique
 * (créée avant ce changement) ou toute anomalie de migration. Gardé par le middleware
 * EnsureOrganizationHasSite (alias `org.site.required`), qui redirige ici tant qu'aucun site
 * n'existe pour l'organisation.
 *
 * Volontairement en dehors du module Sites (pas de middleware `module:sites`) : cette étape est
 * fondamentale au fonctionnement de l'app, pas une fonctionnalité désactivable.
 */
class OnboardingSiteController extends Controller
{
    public function __construct(private readonly InstallationService $service) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $this->authorize('create', Site::class);

        if (! AuthRedirects::needsOnboarding($user)) {
            return redirect()->route('dashboard');
        }

        $org = $user->organization;
        $suggeres = $org?->domaine_activite?->siteTypes() ?? SiteType::cases();

        return Inertia::render('Onboarding/Site', [
            'types_suggeres' => array_map(fn (SiteType $t) => ['value' => $t->value, 'label' => $t->label()], $suggeres),
            'types_tous' => SiteType::options(),
            'organisation_nom' => $org?->name,
        ]);
    }

    public function store(Request $request): RedirectResponse
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
