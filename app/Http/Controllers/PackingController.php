<?php

namespace App\Http\Controllers;

use App\Enums\PackingShift;
use App\Enums\PackingStatut;
use App\Models\Packing;
use App\Models\Parametre;
use App\Models\Prestataire;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PackingController extends Controller
{
    private function packingData(Packing $p): array
    {
        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'prestataire_id' => $p->prestataire_id,
            'prestataire_nom' => $p->prestataire_nom,
            'date' => $p->date?->toDateString(),
            'nb_rouleaux' => $p->nb_rouleaux,
            'prix_par_rouleau' => $p->prix_par_rouleau,
            'montant' => $p->montant,
            'montant_verse' => $p->montant_verse,
            'montant_restant' => $p->montant_restant,
            'shift' => $p->shift?->value,
            'shift_label' => $p->shift?->label(),
            'statut' => $p->statut?->value,
            'statut_label' => $p->statut_label,
            'notes' => $p->notes,
            'can_edit' => $p->peutEtreModifie(),
            'can_cancel' => $p->peutEtreAnnule(),
        ];
    }

    public function index(): Response
    {
        $this->authorize('viewAny', Packing::class);

        $packings = Packing::with('prestataire')
            ->withSum('versements', 'montant')
            ->where('organization_id', auth()->user()->organization_id)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Packing $p) => $this->packingData($p));

        return Inertia::render('Packings/Index', [
            'packings' => $packings,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Packing::class);

        $prestataires = Prestataire::where('organization_id', auth()->user()->organization_id)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get()
            ->map(fn (Prestataire $p) => [
                'value' => $p->id,
                'label' => $p->nom_complet ?? $p->reference,
            ]);

        return Inertia::render('Packings/Create', [
            'prestataires' => $prestataires,
            'prix_defaut' => Parametre::getPrixRouleauDefaut(auth()->user()->organization_id),
            'statuts' => PackingStatut::options(),
            'shifts' => PackingShift::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Packing::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, 'Votre compte n\'est associé à aucune organisation.');

        $data = $request->validate([
            'prestataire_id' => ['required', 'string', Rule::exists('prestataires', 'id')->where('organization_id', $orgId)],
            'date' => 'required|date',
            'shift' => ['required', Rule::in(PackingShift::values())],
            'nb_rouleaux' => 'required|integer|min:1|max:9999999',
            'prix_par_rouleau' => 'required|integer|min:0|max:99999999',
            'statut' => ['nullable', Rule::in([PackingStatut::IMPAYEE->value, PackingStatut::ANNULEE->value])],
            'notes' => 'nullable|string|max:5000',
        ], [
            'prestataire_id.required' => 'Le prestataire est obligatoire.',
            'prestataire_id.exists' => 'Le prestataire sélectionné est introuvable.',
            'date.required' => 'La date est obligatoire.',
            'shift.required' => 'Le shift est obligatoire.',
            'shift.in' => 'Le shift doit être "jour" ou "nuit".',
            'nb_rouleaux.required' => 'Le nombre de rouleaux est obligatoire.',
            'nb_rouleaux.min' => 'Le nombre de rouleaux doit être supérieur à 0.',
            'prix_par_rouleau.required' => 'Le prix par rouleau est obligatoire.',
            'statut.in' => 'Statut invalide à la création.',
        ]);

        $packing = Packing::create([
            ...$data,
            'organization_id' => $orgId,
        ]);

        return redirect()->route('packings.show', $packing)
            ->with('success', 'Packing créé avec succès.');
    }

    public function show(Packing $packing): Response
    {
        $this->authorize('view', $packing);

        $packing->load(['prestataire', 'versements.creator']);
        $packing->loadSum('versements', 'montant');

        $versements = $packing->versements->map(fn ($v) => [
            'id' => $v->id,
            'date' => $v->date?->toDateString(),
            'montant' => $v->montant,
            'notes' => $v->notes,
            'created_by' => $v->creator?->name,
            'created_at' => $v->created_at?->toISOString(),
        ]);

        return Inertia::render('Packings/Show', [
            'packing' => $this->packingData($packing),
            'versements' => $versements,
        ]);
    }

    public function edit(Packing $packing): Response
    {
        $this->authorize('update', $packing);
        abort_unless($packing->peutEtreModifie(), 403, 'Ce packing ne peut plus être modifié.');

        $packing->loadSum('versements', 'montant');

        $prestataires = Prestataire::where('organization_id', auth()->user()->organization_id)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get()
            ->map(fn (Prestataire $p) => [
                'value' => $p->id,
                'label' => $p->nom_complet ?? $p->reference,
            ]);

        return Inertia::render('Packings/Edit', [
            'packing' => $this->packingData($packing),
            'prestataires' => $prestataires,
            'shifts' => PackingShift::options(),
        ]);
    }

    public function update(Request $request, Packing $packing): RedirectResponse
    {
        $this->authorize('update', $packing);
        abort_unless($packing->peutEtreModifie(), 403, 'Ce packing ne peut plus être modifié.');

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'prestataire_id' => ['required', 'string', Rule::exists('prestataires', 'id')->where('organization_id', $orgId)],
            'date' => 'required|date',
            'shift' => ['required', Rule::in(PackingShift::values())],
            'nb_rouleaux' => 'required|integer|min:1|max:9999999',
            'prix_par_rouleau' => 'required|integer|min:0|max:99999999',
            'notes' => 'nullable|string|max:5000',
        ], [
            'prestataire_id.required' => 'Le prestataire est obligatoire.',
            'prestataire_id.exists' => 'Le prestataire sélectionné est introuvable.',
            'date.required' => 'La date est obligatoire.',
            'shift.required' => 'Le shift est obligatoire.',
            'shift.in' => 'Le shift doit être "jour" ou "nuit".',
            'nb_rouleaux.required' => 'Le nombre de rouleaux est obligatoire.',
            'nb_rouleaux.min' => 'Le nombre de rouleaux doit être supérieur à 0.',
            'prix_par_rouleau.required' => 'Le prix par rouleau est obligatoire.',
        ]);

        $packing->update($data);

        return redirect()->route('packings.show', $packing)
            ->with('success', 'Packing mis à jour avec succès.');
    }

    public function destroy(Packing $packing): RedirectResponse
    {
        $this->authorize('delete', $packing);
        abort_unless($packing->peutEtreModifie(), 403, 'Seuls les packings impayés peuvent être supprimés.');

        $packing->delete();

        return redirect()->route('packings.index')
            ->with('success', 'Packing supprimé.');
    }

    public function annuler(Packing $packing): RedirectResponse
    {
        $this->authorize('update', $packing);
        abort_unless($packing->peutEtreAnnule(), 403, 'Ce packing est déjà annulé.');

        $packing->statut = PackingStatut::ANNULEE;
        $packing->saveQuietly();

        return back()->with('success', 'Packing annulé.');
    }
}
