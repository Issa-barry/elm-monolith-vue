<?php

namespace App\Http\Controllers\InstallWizard;

use App\Enums\DomaineActivite;
use App\Enums\SiteType;
use App\Http\Controllers\Controller;
use App\Services\InstallationService;
use App\Support\Auth\InstallWizardGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StoreInstallWizardController extends Controller
{
    public function __construct(
        private readonly InstallationService $service,
        private readonly InstallWizardGuard $guard,
    ) {}

    public function __invoke(Request $request): Response
    {
        abort_if($this->service->isLocked(), 404);
        $this->guard->assertSaasTokenConfigured();
        $this->guard->ensureTokenVerified($request);

        $data = $request->validate([
            'organisation.nom' => 'required|string|max:255',
            'organisation.domaine' => ['required', 'string', Rule::in(array_column(DomaineActivite::cases(), 'value'))],
            'admin.prenom' => 'required|string|max:100',
            'admin.nom' => 'required|string|max:100',
            'admin.telephone' => 'required|string',
            // Obligatoire en on_premise, facultatif en saas — même règle appliquée en aval par
            // InstallationService::install() (seule source de vérité, revalidée indépendamment de
            // cette règle-ci qui ne sert qu'à renvoyer une erreur tôt, avec le bon message).
            'admin.email' => [$this->service->isSaas() ? 'nullable' : 'required', 'email:rfc,dns', 'max:255'],
            'admin.password' => 'required|string',
            // Le mot de passe est saisi une seule fois (pas de champ de confirmation dans le
            // formulaire) — `nullable` seulement pour ne pas casser un éventuel appel API qui en
            // enverrait quand même un, auquel cas InstallationService le revalide (doit alors
            // correspondre au mot de passe, cf. sa règle `confirmed`).
            'admin.password_confirmation' => 'nullable|string',
            'site.type' => ['required', 'string', Rule::in(array_column(SiteType::cases(), 'value'))],
            'site.ville' => 'required|string|max:100',
            'site.quartier' => 'required|string|max:100',
        ]);

        // La complexité/confirmation du mot de passe est revalidée par InstallationService
        // (seule source de vérité, partagée avec le CLI) — pas de règle `confirmed` ici.
        $this->service->install(
            organisation: $data['organisation'],
            admin: $data['admin'],
            site: $data['site'],
        );

        $request->session()->forget('install_token_verified');

        // Rendu direct (pas de redirect) : la page Success.vue affiche la confirmation puis un
        // bouton "Se connecter" — en on_premise, l'installation venant de se terminer, un GET
        // immédiat sur /install redirigerait de toute façon vers /login (isLocked() est
        // maintenant vrai) ; en saas, /install resterait au contraire accessible pour une
        // organisation suivante, ce qui ne change rien à l'intérêt de rendre Success ici.
        return Inertia::render('Install/Success');
    }
}
