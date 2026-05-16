<?php

namespace App\Http\Controllers;

use App\Enums\StatutContrat;
use App\Enums\TypeContrat;
use App\Models\Contrat;
use App\Models\Employe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContratController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Contrat::class);

        $orgId   = auth()->user()->organization_id;
        $filters = $request->only(['statut_contrat', 'type_contrat']);

        $query = Contrat::with('employe:id,nom,prenom,matricule')
            ->where('organization_id', $orgId);

        if (! empty($filters['statut_contrat'])) {
            $query->where('statut_contrat', $filters['statut_contrat']);
        }

        if (! empty($filters['type_contrat'])) {
            $query->where('type_contrat', $filters['type_contrat']);
        }

        $contrats = $query->orderByDesc('date_debut')->get()
            ->map(fn (Contrat $c) => $this->toRow($c));

        return Inertia::render('Contrats/Index', [
            'contrats'              => $contrats,
            'filters'               => $filters,
            'type_contrat_options'  => TypeContrat::options(),
            'statut_contrat_options' => StatutContrat::options(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Contrat::class);

        $orgId = auth()->user()->organization_id;

        $employes = Employe::where('organization_id', $orgId)
            ->where('statut', 'actif')
            ->orderBy('nom')
            ->get(['id', 'nom', 'prenom', 'matricule'])
            ->map(fn ($e) => [
                'value' => $e->id,
                'label' => "{$e->prenom} {$e->nom} ({$e->matricule})",
            ])->values();

        return Inertia::render('Contrats/Create', [
            'employes'              => $employes,
            'type_contrat_options'  => TypeContrat::options(),
            'statut_contrat_options' => StatutContrat::options(),
            'employe_id_prefill'    => $request->query('employe_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Contrat::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403);

        $data = $request->validate($this->rules($request), $this->messages());

        // Vérifier que l'employé appartient à l'organisation
        $employe = Employe::where('id', $data['employe_id'])
            ->where('organization_id', $orgId)
            ->firstOrFail();

        // Un seul contrat actif par employé
        $dejaActif = Contrat::where('employe_id', $employe->id)
            ->where('statut_contrat', StatutContrat::ACTIF->value)
            ->exists();

        if ($dejaActif) {
            return back()->withErrors(['employe_id' => 'Cet employé a déjà un contrat actif. Terminez-le avant d\'en créer un nouveau.']);
        }

        // CDI => date_fin null
        if ($data['type_contrat'] === TypeContrat::CDI->value) {
            $data['date_fin'] = null;
        }

        Contrat::create(array_merge($data, [
            'organization_id' => $orgId,
            'statut_contrat'  => StatutContrat::ACTIF->value,
        ]));

        return redirect()->route('employes.edit', $employe)
            ->with('success', 'Contrat créé avec succès.');
    }

    public function edit(Contrat $contrat): Response
    {
        $this->authorize('update', $contrat);

        $orgId = auth()->user()->organization_id;

        $employes = Employe::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'prenom', 'matricule'])
            ->map(fn ($e) => [
                'value' => $e->id,
                'label' => "{$e->prenom} {$e->nom} ({$e->matricule})",
            ])->values();

        return Inertia::render('Contrats/Edit', [
            'contrat'               => $this->toRow($contrat),
            'employes'              => $employes,
            'type_contrat_options'  => TypeContrat::options(),
            'statut_contrat_options' => StatutContrat::options(),
        ]);
    }

    public function update(Request $request, Contrat $contrat): RedirectResponse
    {
        $this->authorize('update', $contrat);

        $data = $request->validate($this->rules($request, $contrat), $this->messages());

        // CDI => date_fin null
        if ($data['type_contrat'] === TypeContrat::CDI->value) {
            $data['date_fin'] = null;
        }

        $contrat->update($data);

        return redirect()->route('employes.edit', $contrat->employe_id)
            ->with('success', 'Contrat mis à jour.');
    }

    public function destroy(Contrat $contrat): RedirectResponse
    {
        $this->authorize('delete', $contrat);

        $employeId = $contrat->employe_id;
        $contrat->delete();

        return redirect()->route('employes.edit', $employeId)
            ->with('success', 'Contrat supprimé.');
    }

    private function rules(Request $request, ?Contrat $contrat = null): array
    {
        $isCdd = $request->input('type_contrat') === TypeContrat::CDD->value;

        return [
            'employe_id'    => ['required', 'exists:employes,id'],
            'type_contrat'  => ['required', Rule::in(TypeContrat::values())],
            'date_debut'    => 'required|date',
            'date_fin'      => $isCdd
                ? ['required', 'date', 'after_or_equal:date_debut']
                : ['nullable', 'date', 'after_or_equal:date_debut'],
            'salaire_base'  => 'nullable|numeric|min:0',
            'statut_contrat' => ['nullable', Rule::in(StatutContrat::values())],
        ];
    }

    private function messages(): array
    {
        return [
            'employe_id.required'   => "L'employé est obligatoire.",
            'employe_id.exists'     => 'Employé introuvable.',
            'type_contrat.required' => 'Le type de contrat est obligatoire.',
            'type_contrat.in'       => 'Type de contrat invalide.',
            'date_debut.required'   => 'La date de début est obligatoire.',
            'date_fin.required'     => 'La date de fin est obligatoire pour un CDD.',
            'date_fin.after_or_equal' => 'La date de fin doit être égale ou postérieure à la date de début.',
            'salaire_base.numeric'  => 'Le salaire doit être un nombre.',
            'salaire_base.min'      => 'Le salaire ne peut pas être négatif.',
        ];
    }

    private function toRow(Contrat $c): array
    {
        $c->loadMissing('employe:id,nom,prenom,matricule');

        return [
            'id'                    => $c->id,
            'employe_id'            => $c->employe_id,
            'employe_nom_complet'   => $c->employe ? trim("{$c->employe->prenom} {$c->employe->nom}") : null,
            'employe_matricule'     => $c->employe?->matricule,
            'type_contrat'          => $c->type_contrat->value,
            'type_contrat_label'    => $c->type_contrat->label(),
            'statut_contrat'        => $c->statut_contrat->value,
            'statut_contrat_label'  => $c->statut_contrat->label(),
            'date_debut'            => $c->date_debut?->format('Y-m-d'),
            'date_fin'              => $c->date_fin?->format('Y-m-d'),
            'salaire_base'          => $c->salaire_base,
        ];
    }
}
