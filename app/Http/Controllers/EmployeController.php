<?php

namespace App\Http\Controllers;

use App\Enums\StatutContrat;
use App\Enums\StatutEmploye;
use App\Enums\TypeContrat;
use App\Enums\TypeEmploye;
use App\Models\Employe;
use App\Models\Personne;
use App\Models\Site;
use App\Services\MatriculeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmployeController extends Controller
{
    private function siteOptions(string $orgId): array
    {
        return Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'code'])
            ->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->nom} ({$s->code})"])
            ->values()
            ->all();
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Employe::class);

        $orgId = auth()->user()->organization_id;
        $filters = $request->only(['statut', 'type_employe', 'type_contrat', 'search']);

        $query = Employe::with(['personne', 'site:id,nom,code', 'contratActif'])
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
            'statut' => ['required', Rule::in(StatutEmploye::values())],
        ], $this->messages());

        $this->assertEmailUniqueInOrg($data['email'] ?? null, $orgId);

        $matricule = app(MatriculeService::class)->generate($orgId, Employe::class);

        $personne = Personne::resoudreOuCreer($orgId, [
            'nom' => mb_strtoupper($data['nom'], 'UTF-8'),
            'prenom' => mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            'email' => $data['email'] ?? null,
            'telephone' => $data['telephone'] ?? null,
        ]);

        $employe = Employe::create([
            'organization_id' => $orgId,
            'personne_id' => $personne->id,
            'matricule' => $matricule,
            'type_employe' => $data['type_employe'],
            'site_id' => $data['site_id'] ?? null,
            'statut' => $data['statut'],
        ]);

        return redirect()->route('employes.edit', $employe)
            ->with('success', "{$employe->nom_complet} a été créé avec succès.");
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
            'statut' => ['required', Rule::in(StatutEmploye::values())],
        ], $this->messages());

        $this->assertEmailUniqueInOrg($data['email'] ?? null, $orgId, $employe->id);

        $employe->personne->update([
            'nom' => mb_strtoupper($data['nom'], 'UTF-8'),
            'prenom' => mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            'email' => $data['email'] ?? null,
            'telephone' => $data['telephone'] ?? null,
            'telephone_normalise' => $data['telephone'] ? Personne::normaliserTelephone($data['telephone']) : null,
        ]);

        $employe->update([
            'type_employe' => $data['type_employe'],
            'site_id' => $data['site_id'] ?? null,
            'statut' => $data['statut'],
        ]);

        return redirect()->route('employes.edit', $employe)
            ->with('success', "{$employe->nom_complet} a été mis à jour.");
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
        $employe->load(['personne', 'site:id,nom,code', 'contratActif', 'contrats' => fn ($q) => $q->orderByDesc('date_debut')]);

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
