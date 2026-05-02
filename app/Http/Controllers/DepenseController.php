<?php

namespace App\Http\Controllers;

use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepenseController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Depense::class);

        $orgId = auth()->user()->organization_id;

        $query = Depense::with(['depenseType', 'vehicule', 'site', 'user'])
            ->forOrg($orgId)
            ->orderByDesc('date_depense')
            ->orderByDesc('created_at');

        if ($request->filled('type')) {
            $query->where('depense_type_id', $request->type);
        }
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }
        if ($request->filled('date_debut')) {
            $query->where('date_depense', '>=', $request->date_debut);
        }
        if ($request->filled('date_fin')) {
            $query->where('date_depense', '<=', $request->date_fin);
        }

        $depenses = $query->paginate(30)->withQueryString();

        $types = DepenseType::where('organization_id', $orgId)
            ->active()
            ->ordered()
            ->get(['id', 'code', 'libelle']);

        return Inertia::render('Depenses/Index', [
            'depenses' => $depenses->through(fn (Depense $d) => [
                'id'           => $d->id,
                'montant'      => (float) $d->montant,
                'date_depense' => $d->date_depense->toDateString(),
                'statut'       => $d->statut,
                'commentaire'  => $d->commentaire,
                'type'         => ['id' => $d->depenseType->id, 'libelle' => $d->depenseType->libelle, 'code' => $d->depenseType->code],
                'vehicule'     => $d->vehicule ? ['id' => $d->vehicule->id, 'nom' => $d->vehicule->nom_vehicule] : null,
                'site'         => $d->site ? ['id' => $d->site->id, 'nom' => $d->site->nom] : null,
                'user'         => ['id' => $d->user->id, 'name' => $d->user->name],
            ]),
            'types'   => $types,
            'filters' => $request->only(['type', 'statut', 'date_debut', 'date_fin']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Depense::class);

        $orgId = auth()->user()->organization_id;

        $types = DepenseType::where('organization_id', $orgId)
            ->active()
            ->ordered()
            ->get(['id', 'code', 'libelle', 'requires_vehicle', 'requires_comment']);

        $vehicules = Vehicule::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom_vehicule')
            ->get(['id', 'nom_vehicule', 'immatriculation']);

        $sites = Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'type']);

        $defaultSite = auth()->user()->sites()
            ->wherePivot('is_default', true)
            ->select('sites.id')
            ->first();

        return Inertia::render('Depenses/Create', [
            'types'        => $types,
            'vehicules'    => $vehicules,
            'sites'        => $sites,
            'default_site_id' => $defaultSite?->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Depense::class);

        $orgId = auth()->user()->organization_id;

        $type = DepenseType::where('organization_id', $orgId)
            ->findOrFail($request->depense_type_id);

        $validated = $request->validate([
            'depense_type_id' => ['required', 'ulid'],
            'vehicule_id'     => [$type->requires_vehicle ? 'required' : 'nullable', 'ulid', 'exists:vehicules,id'],
            'site_id'         => ['nullable', 'ulid', 'exists:sites,id'],
            'montant'         => ['required', 'numeric', 'min:0.01'],
            'date_depense'    => ['required', 'date'],
            'commentaire'     => [$type->requires_comment ? 'required' : 'nullable', 'string', 'max:1000'],
            'statut'          => ['required', 'in:brouillon,soumis'],
        ]);

        Depense::create([
            ...$validated,
            'organization_id' => $orgId,
            'user_id'         => auth()->id(),
        ]);

        return redirect()->route('depenses.index')->with('success', 'Dépense enregistrée.');
    }

    public function edit(Depense $depense): Response
    {
        $this->authorize('update', $depense);

        $orgId = auth()->user()->organization_id;

        $types = DepenseType::where('organization_id', $orgId)
            ->active()
            ->ordered()
            ->get(['id', 'code', 'libelle', 'requires_vehicle', 'requires_comment']);

        $vehicules = Vehicule::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom_vehicule')
            ->get(['id', 'nom_vehicule', 'immatriculation']);

        $sites = Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'type']);

        return Inertia::render('Depenses/Edit', [
            'depense'  => [
                'id'              => $depense->id,
                'depense_type_id' => $depense->depense_type_id,
                'vehicule_id'     => $depense->vehicule_id,
                'site_id'         => $depense->site_id,
                'montant'         => (float) $depense->montant,
                'date_depense'    => $depense->date_depense->toDateString(),
                'commentaire'     => $depense->commentaire ?? '',
                'statut'          => $depense->statut,
            ],
            'types'    => $types,
            'vehicules' => $vehicules,
            'sites'    => $sites,
        ]);
    }

    public function update(Request $request, Depense $depense): RedirectResponse
    {
        $this->authorize('update', $depense);

        $orgId = auth()->user()->organization_id;

        $type = DepenseType::where('organization_id', $orgId)
            ->findOrFail($request->depense_type_id);

        $validated = $request->validate([
            'depense_type_id' => ['required', 'ulid'],
            'vehicule_id'     => [$type->requires_vehicle ? 'required' : 'nullable', 'ulid', 'exists:vehicules,id'],
            'site_id'         => ['nullable', 'ulid', 'exists:sites,id'],
            'montant'         => ['required', 'numeric', 'min:0.01'],
            'date_depense'    => ['required', 'date'],
            'commentaire'     => [$type->requires_comment ? 'required' : 'nullable', 'string', 'max:1000'],
            'statut'          => ['required', 'in:brouillon,soumis,approuve,rejete'],
        ]);

        $depense->update($validated);

        return redirect()->route('depenses.index')->with('success', 'Dépense mise à jour.');
    }

    public function approuver(Depense $depense): RedirectResponse
    {
        $this->authorize('update', $depense);

        if ($depense->statut !== 'soumis') {
            return back()->withErrors(['statut' => 'Seules les dépenses soumises peuvent être approuvées.']);
        }

        $depense->update(['statut' => 'approuve']);

        return back()->with('success', 'Dépense approuvée.');
    }

    public function rejeter(Depense $depense): RedirectResponse
    {
        $this->authorize('update', $depense);

        if ($depense->statut !== 'soumis') {
            return back()->withErrors(['statut' => 'Seules les dépenses soumises peuvent être rejetées.']);
        }

        $depense->update(['statut' => 'rejete']);

        return back()->with('success', 'Dépense rejetée.');
    }

    public function destroy(Depense $depense): RedirectResponse
    {
        $this->authorize('delete', $depense);

        $depense->delete();

        return back()->with('success', 'Dépense supprimée.');
    }
}
