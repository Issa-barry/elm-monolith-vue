<?php

namespace App\Http\Controllers;

use App\Enums\StatutContrat;
use App\Enums\StatutEmploye;
use App\Enums\TypeContrat;
use App\Enums\TypeEmploye;
use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\Personne;
use App\Models\Site;
use App\Services\MatriculeService;
use App\Services\PhoneCountryInfo;
use App\Services\Rh\EmployeAffectationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmployeController extends Controller
{
    /**
     * Résout un téléphone saisi via PhoneCountryInput.vue (numéro complet, ex: "+224613855281")
     * en composantes pays — même mécanisme que InstallationService::resolveTelephone(), pas de
     * second champ `code_pays` à valider séparément (à la différence de PhoneHandlerTrait, conçu
     * pour des formulaires à deux champs comme ProprietaireController). Lève une erreur de
     * validation explicite plutôt que de laisser passer un numéro non exploitable.
     *
     * @return array{telephone: string, code_pays: string, pays: string, code_phone_pays: string}
     */
    private function resolveTelephone(string $telephone): array
    {
        $info = PhoneCountryInfo::resolve($telephone);

        if ($info === null) {
            throw ValidationException::withMessages([
                'telephone' => 'Numéro invalide ou indicatif non reconnu. Utilisez le format international (ex : +224613855281).',
            ]);
        }

        return [
            'telephone' => $info['telephone'],
            'code_pays' => $info['code_pays'],
            'pays' => $info['pays'],
            'code_phone_pays' => $info['indicatif'],
        ];
    }

    /**
     * Un numéro déjà utilisé par une AUTRE Personne de l'organisation ferait échouer
     * `$employe->personne->update()` sur la contrainte unique (organization_id,
     * telephone_normalise) avec une QueryException brute (500) plutôt qu'une erreur de formulaire
     * — cf. personnes table. Pas de garde équivalente côté store() : Personne::resoudreOuCreer()
     * gère déjà ce cas en RATTACHANT la Personne existante (comportement intentionnel déjà en
     * place, une même personne pouvant cumuler plusieurs rôles), jamais en le rejetant.
     */
    private function assertTelephoneUniqueInOrg(string $telephone, string $orgId, string $ignorePersonneId): void
    {
        $exists = Personne::where('organization_id', $orgId)
            ->where('telephone_normalise', Personne::normaliserTelephone($telephone))
            ->where('id', '!=', $ignorePersonneId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'telephone' => 'Ce numéro de téléphone est déjà utilisé par une autre personne de votre organisation.',
            ]);
        }
    }

    private function siteOptions(string $orgId): array
    {
        return Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'code'])
            ->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->nom} ({$s->code})"])
            ->values()
            ->all();
    }

    private function fonctionOptions(string $orgId): array
    {
        return FonctionRh::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('libelle')
            ->get(['id', 'libelle', 'code'])
            ->map(fn (FonctionRh $f) => ['value' => $f->id, 'label' => $f->libelle])
            ->values()
            ->all();
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Employe::class);

        $orgId = auth()->user()->organization_id;
        $filters = $request->only(['statut', 'type_employe', 'type_contrat', 'search']);

        $query = Employe::with(['personne', 'site:id,nom,code', 'fonction:id,libelle', 'contratActif'])
            ->where('organization_id', $orgId);

        if (! empty($filters['statut'])) {
            $query->where('statut', $filters['statut']);
        }

        if (! empty($filters['type_employe'])) {
            $query->where('type_employe', $filters['type_employe']);
        }

        if (! empty($filters['type_contrat'])) {
            $query->whereHas('contrats', function ($q) use ($filters) {
                $q->where('type_contrat', $filters['type_contrat'])
                    ->where('statut_contrat', StatutContrat::ACTIF->value);
            });
        }

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('matricule', $s)
                    ->orWhereHas('personne', function ($q2) use ($s) {
                        $q2->where('nom', 'like', "%{$s}%")
                            ->orWhere('prenom', 'like', "%{$s}%")
                            ->orWhere('telephone', 'like', "%{$s}%")
                            ->orWhere('email', 'like', "%{$s}%");
                    });
            });
        }

        $employes = $query->get()
            ->sortBy(['nom', 'prenom'])
            ->map(fn (Employe $e) => $this->toRow($e))
            ->values();

        return Inertia::render('Employes/Index', [
            'employes' => $employes,
            'filters' => $filters,
            'statut_options' => StatutEmploye::options(),
            'type_employe_options' => TypeEmploye::options(),
            'type_contrat_options' => TypeContrat::options(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Employe::class);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Employes/Create', [
            'type_employe_options' => TypeEmploye::options(),
            'statut_options' => StatutEmploye::options(),
            'sites' => $this->siteOptions($orgId),
            'fonctions' => $this->fonctionOptions($orgId),
        ]);
    }

    /** Remplace unique:employes,email — email vit désormais sur personnes. */
    private function assertEmailUniqueInOrg(?string $email, string $orgId, ?string $ignoreEmployeId = null): void
    {
        if (! $email) {
            return;
        }

        $exists = Employe::where('organization_id', $orgId)
            ->whereHas('personne', fn ($q) => $q->where('email', $email))
            ->when($ignoreEmployeId, fn ($q) => $q->where('id', '!=', $ignoreEmployeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'email' => 'Cette adresse e-mail est déjà utilisée.',
            ]);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Employe::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403);

        $data = $request->validate([
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => 'nullable|string|max:50',
            'type_employe' => ['required', Rule::in(TypeEmploye::values())],
            'site_id' => 'nullable|exists:sites,id',
            'fonction_rh_id' => ['nullable', Rule::exists('fonctions_rh', 'id')->where('organization_id', $orgId)],
            'statut' => ['required', Rule::in(StatutEmploye::values())],
        ], $this->messages());

        $this->assertEmailUniqueInOrg($data['email'] ?? null, $orgId);

        $telephoneInfo = filled($data['telephone'] ?? null) ? $this->resolveTelephone($data['telephone']) : null;

        $matricule = app(MatriculeService::class)->generate($orgId, Employe::class);

        $personne = Personne::resoudreOuCreer($orgId, [
            'nom' => mb_strtoupper($data['nom'], 'UTF-8'),
            'prenom' => mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            'email' => $data['email'] ?? null,
            'telephone' => $telephoneInfo['telephone'] ?? null,
            'code_pays' => $telephoneInfo['code_pays'] ?? null,
            'pays' => $telephoneInfo['pays'] ?? null,
            'code_phone_pays' => $telephoneInfo['code_phone_pays'] ?? null,
        ]);

        $employe = Employe::create([
            'organization_id' => $orgId,
            'personne_id' => $personne->id,
            'matricule' => $matricule,
            'type_employe' => $data['type_employe'],
            'statut' => $data['statut'],
        ]);

        // Le site/la fonction passent TOUJOURS par EmployeAffectationService — jamais une écriture
        // directe de employes.site_id ici, sans quoi le cache et l'historique divergeraient dès la
        // création (cf. plan §2).
        $site = isset($data['site_id']) ? Site::find($data['site_id']) : null;
        $fonction = isset($data['fonction_rh_id']) ? FonctionRh::find($data['fonction_rh_id']) : null;
        if ($site !== null) {
            app(EmployeAffectationService::class)->definir($employe, $site, $fonction, auth()->user());
        }

        return redirect()->route('employes.edit', $employe)
            ->with('success', "{$employe->nom_complet} a été créé avec succès.");
    }

    /**
     * Fiche employé en lecture seule : identité, fonction/site actuels, contrat + historique,
     * historique d'affectation, compte applicatif et profil d'accès CLAIREMENT séparés de la
     * fonction RH (jamais la même notion) — aucune commission n'intervient plus ici (la
     * commission est désormais attribuée directement au site, jamais à un employé/gérant).
     */
    public function show(Employe $employe): Response
    {
        $this->authorize('view', $employe);

        $employe->load(['personne.user.roles']);

        return Inertia::render('Employes/Show', [
            'employe' => array_merge($this->toDetail($employe), [
                'compte' => $this->compteInfo($employe),
            ]),
        ]);
    }

    /**
     * null = aucun compte applicatif rattaché (cas normal : un employé n'a jamais besoin d'un
     * compte pour exister côté RH, cf. mission §"jamais un compte utilisateur requis") — la vue
     * affiche alors explicitement « Aucun compte », jamais une section vide silencieuse.
     */
    private function compteInfo(Employe $employe): ?array
    {
        $user = $employe->personne?->user;
        if ($user === null) {
            return null;
        }

        $role = $user->roles->first();
        $pending = $user->isPendingValidation();

        return [
            'id' => $user->id,
            'email' => $user->email,
            // Profil d'accès (rôle Spatie) — toujours affiché séparément de la fonction RH,
            // jamais fusionné : deux notions distinctes (cf. mission).
            'role_label' => $role ? ($role->label ?? $role->name) : null,
            'statut' => $pending ? 'pending_validation' : ($user->is_active ? 'actif' : 'inactif'),
            'statut_label' => $pending ? 'En attente de validation' : ($user->is_active ? 'Actif' : 'Inactif'),
        ];
    }

    public function edit(Employe $employe): Response
    {
        $this->authorize('update', $employe);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Employes/Edit', [
            'employe' => $this->toDetail($employe),
            'type_employe_options' => TypeEmploye::options(),
            'statut_options' => StatutEmploye::options(),
            'sites' => $this->siteOptions($orgId),
            'fonctions' => $this->fonctionOptions($orgId),
        ]);
    }

    public function update(Request $request, Employe $employe): RedirectResponse
    {
        $this->authorize('update', $employe);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => 'nullable|string|max:50',
            'type_employe' => ['required', Rule::in(TypeEmploye::values())],
            'site_id' => 'nullable|exists:sites,id',
            'fonction_rh_id' => ['nullable', Rule::exists('fonctions_rh', 'id')->where('organization_id', $orgId)],
            'statut' => ['required', Rule::in(StatutEmploye::values())],
        ], $this->messages());

        $this->assertEmailUniqueInOrg($data['email'] ?? null, $orgId, $employe->id);

        $telephoneInfo = filled($data['telephone'] ?? null) ? $this->resolveTelephone($data['telephone']) : null;
        if ($telephoneInfo !== null) {
            $this->assertTelephoneUniqueInOrg($telephoneInfo['telephone'], $orgId, $employe->personne_id);
        }

        $employe->personne->update([
            'nom' => mb_strtoupper($data['nom'], 'UTF-8'),
            'prenom' => mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            'email' => $data['email'] ?? null,
            'telephone' => $telephoneInfo['telephone'] ?? null,
            'telephone_normalise' => $telephoneInfo !== null ? Personne::normaliserTelephone($telephoneInfo['telephone']) : null,
            'code_pays' => $telephoneInfo['code_pays'] ?? null,
            'pays' => $telephoneInfo['pays'] ?? null,
            'code_phone_pays' => $telephoneInfo['code_phone_pays'] ?? null,
        ]);

        $employe->update([
            'type_employe' => $data['type_employe'],
            'statut' => $data['statut'],
        ]);

        // cf. store() : jamais d'écriture directe de employes.site_id/fonction_rh_id, toujours
        // via EmployeAffectationService (cache + historique synchronisés en un seul appel).
        $site = isset($data['site_id']) ? Site::find($data['site_id']) : null;
        $fonction = isset($data['fonction_rh_id']) ? FonctionRh::find($data['fonction_rh_id']) : null;
        app(EmployeAffectationService::class)->definir($employe, $site, $fonction, auth()->user());

        return redirect()->route('employes.edit', $employe)
            ->with('success', "{$employe->nom_complet} a été mis à jour.");
    }

    /**
     * Transfert explicite de site depuis l'écran RH (distinct de update() : action ciblée, avec
     * son propre motif "transfert", conserve la fonction actuelle de l'employé inchangée).
     */
    public function transfererSite(Request $request, Employe $employe): RedirectResponse
    {
        $this->authorize('update', $employe);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'site_id' => ['required', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
        ], [
            'site_id.required' => 'Le site est obligatoire.',
        ]);

        $site = Site::findOrFail($data['site_id']);

        app(EmployeAffectationService::class)->definir($employe, $site, $employe->fonction, auth()->user(), 'transfert');

        return redirect()->route('employes.edit', $employe)
            ->with('success', "{$employe->nom_complet} a été transféré vers {$site->nom}.");
    }

    public function destroy(Employe $employe): RedirectResponse
    {
        $this->authorize('delete', $employe);

        $employe->delete();

        return redirect()->route('employes.index')
            ->with('success', "{$employe->nom_complet} a été supprimé.");
    }

    private function toRow(Employe $e): array
    {
        $contrat = $e->contratActif;

        return [
            'id' => $e->id,
            'matricule' => $e->matricule,
            'nom_complet' => $e->nom_complet,
            'nom' => $e->nom,
            'prenom' => $e->prenom,
            'email' => $e->email,
            'telephone' => $e->telephone,
            'type_employe' => $e->type_employe->value,
            'type_employe_label' => $e->type_employe->label(),
            'statut' => $e->statut->value,
            'statut_label' => $e->statut->label(),
            'site_id' => $e->site_id,
            'site' => $e->site ? "{$e->site->nom} ({$e->site->code})" : null,
            'fonction_rh_id' => $e->fonction_rh_id,
            'fonction_libelle' => $e->fonction?->libelle,
            'contrat_actif' => $contrat ? [
                'id' => $contrat->id,
                'type_contrat' => $contrat->type_contrat->value,
                'type_contrat_label' => $contrat->type_contrat->label(),
                'date_debut' => $contrat->date_debut?->format('d/m/Y'),
                'date_fin' => $contrat->date_fin?->format('d/m/Y'),
            ] : null,
        ];
    }

    private function toDetail(Employe $employe): array
    {
        $employe->load([
            'personne', 'site:id,nom,code', 'fonction:id,libelle', 'contratActif',
            'contrats' => fn ($q) => $q->orderByDesc('date_debut'),
            'affectations.site:id,nom,code', 'affectations.fonction:id,libelle',
        ]);

        return array_merge($this->toRow($employe), [
            'contrats' => $employe->contrats->map(fn ($c) => [
                'id' => $c->id,
                'type_contrat' => $c->type_contrat->value,
                'type_contrat_label' => $c->type_contrat->label(),
                'statut_contrat' => $c->statut_contrat->value,
                'statut_contrat_label' => $c->statut_contrat->label(),
                'date_debut' => $c->date_debut?->format('d/m/Y'),
                'date_fin' => $c->date_fin?->format('d/m/Y'),
                'salaire_base' => $c->salaire_base,
            ])->values(),
            // Historique d'affectation (site + fonction) — le plus récent en premier, cf.
            // Employe::affectations() (orderByDesc('debut_at')).
            'affectations' => $employe->affectations->map(fn ($a) => [
                'id' => $a->id,
                'site' => $a->site ? "{$a->site->nom} ({$a->site->code})" : null,
                'fonction_libelle' => $a->fonction?->libelle,
                'debut_at' => $a->debut_at?->format('d/m/Y H:i'),
                'fin_at' => $a->fin_at?->format('d/m/Y H:i'),
                'active' => $a->fin_at === null,
                'motif' => $a->motif,
            ])->values(),
        ]);
    }

    private function messages(): array
    {
        return [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.email' => "L'adresse e-mail est invalide.",
            'type_employe.required' => 'Le type d\'employé est obligatoire.',
            'type_employe.in' => 'Type d\'employé invalide.',
            'statut.required' => 'Le statut est obligatoire.',
            'statut.in' => 'Statut invalide.',
        ];
    }
}
