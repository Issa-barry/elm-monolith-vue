<?php

namespace App\Http\Controllers;

use App\Enums\StatutPropositionVehicule;
use App\Models\PropositionVehicule;
use App\Models\Vehicule;
use App\Services\PropositionConversionService;
use App\Support\Periodes\Periode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PropositionVehiculeController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PropositionVehicule::class);

        $orgId = auth()->user()->organization_id;
        $statut = $request->input('statut');
        $periode = Periode::depuisRequeteValidee($request);

        $propositions = PropositionVehicule::where('organization_id', $orgId)
            ->when($statut, fn ($q) => $q->where('statut', $statut))
            ->tap(fn ($q) => $periode->appliquer($q, 'created_at'))
            ->latest()
            ->get()
            ->map(fn (PropositionVehicule $p) => [
                'id' => $p->id,
                'nom_contact' => $p->nom_contact,
                'telephone_contact' => $p->telephone_contact,
                'nom_vehicule' => $p->nom_vehicule,
                'immatriculation' => $p->immatriculation,
                'type_vehicule' => $p->type_vehicule,
                'statut' => $p->statut?->value,
                'statut_label' => $p->statut_label,
                'statut_color' => $p->statut?->color() ?? 'gray',
                'created_at_label' => $p->created_at?->format('d/m/Y'),
                'photo_url' => $p->photo_url,
            ]);

        return Inertia::render('Vehicules/Propositions/Index', [
            'propositions' => $propositions,
            'statuts' => StatutPropositionVehicule::options(),
            'filters' => [
                'statut' => $statut,
                ...$periode->valeursFiltre(),
            ],
        ]);
    }

    public function show(PropositionVehicule $propositionVehicule): Response
    {
        $this->authorize('view', $propositionVehicule);

        $propositionVehicule->load(['user', 'proprietaire', 'traitePar']);

        $immatriculation = mb_strtoupper(trim((string) $propositionVehicule->immatriculation), 'UTF-8');
        $doublon = Vehicule::where('organization_id', $propositionVehicule->organization_id)
            ->where('immatriculation', $immatriculation)
            ->whereNull('deleted_at')
            ->select('id', 'nom_vehicule', 'immatriculation')
            ->first();

        return Inertia::render('Vehicules/Propositions/Show', [
            'proposition' => [
                'id' => $propositionVehicule->id,
                'nom_contact' => $propositionVehicule->nom_contact,
                'telephone_contact' => $propositionVehicule->telephone_contact,
                'nom_vehicule' => $propositionVehicule->nom_vehicule,
                'marque' => $propositionVehicule->marque,
                'modele' => $propositionVehicule->modele,
                'immatriculation' => $propositionVehicule->immatriculation,
                'type_vehicule' => $propositionVehicule->type_vehicule,
                'capacite_packs' => $propositionVehicule->capacite_packs,
                'commentaire' => $propositionVehicule->commentaire,
                'photo_url' => $propositionVehicule->photo_url,
                'statut' => $propositionVehicule->statut?->value,
                'statut_label' => $propositionVehicule->statut_label,
                'statut_color' => $propositionVehicule->statut?->color() ?? 'gray',
                'decision_note' => $propositionVehicule->decision_note,
                'traitee_at_label' => $propositionVehicule->traitee_at?->format('d/m/Y H:i'),
                'traitee_par_nom' => $propositionVehicule->traitePar?->name,
                'created_at_label' => $propositionVehicule->created_at?->format('d/m/Y H:i'),
                'user_name' => $propositionVehicule->user?->name,
                'proprietaire_nom' => $propositionVehicule->proprietaire
                    ? trim($propositionVehicule->proprietaire->prenom.' '.$propositionVehicule->proprietaire->nom)
                    : null,
                'is_terminal' => $propositionVehicule->statut?->isTerminal() ?? false,
            ],
            'vehicule_doublon' => $doublon ? [
                'id' => $doublon->id,
                'nom_vehicule' => $doublon->nom_vehicule,
                'immatriculation' => $doublon->immatriculation,
            ] : null,
        ]);
    }

    public function priseEnCharge(PropositionVehicule $propositionVehicule): RedirectResponse
    {
        $this->authorize('update', $propositionVehicule);

        $propositionVehicule->update([
            'statut' => StatutPropositionVehicule::EN_REVISION->value,
            'traitee_par' => auth()->id(),
        ]);

        return back()->with('success', 'Proposition prise en charge — statut passé en révision.');
    }

    public function demanderComplement(Request $request, PropositionVehicule $propositionVehicule): RedirectResponse
    {
        $this->authorize('update', $propositionVehicule);

        $validated = $request->validate([
            'decision_note' => ['required', 'string', 'max:1000'],
        ], [
            'decision_note.required' => 'Un message explicatif est obligatoire.',
        ]);

        $propositionVehicule->update([
            'statut' => StatutPropositionVehicule::A_COMPLETER->value,
            'decision_note' => $validated['decision_note'],
            'traitee_par' => auth()->id(),
            'traitee_at' => now(),
        ]);

        return back()->with('success', 'Demande de complément enregistrée. Le partenaire sera informé.');
    }

    public function rejeter(Request $request, PropositionVehicule $propositionVehicule): RedirectResponse
    {
        $this->authorize('update', $propositionVehicule);

        $validated = $request->validate([
            'decision_note' => ['required', 'string', 'max:1000'],
        ], [
            'decision_note.required' => 'Un motif de rejet est obligatoire.',
        ]);

        $propositionVehicule->update([
            'statut' => StatutPropositionVehicule::REJETEE->value,
            'decision_note' => $validated['decision_note'],
            'traitee_par' => auth()->id(),
            'traitee_at' => now(),
        ]);

        return redirect()
            ->route('propositions-vehicules.index')
            ->with('success', 'Proposition rejetée.');
    }

    public function valider(PropositionVehicule $propositionVehicule, PropositionConversionService $service): RedirectResponse
    {
        $this->authorize('update', $propositionVehicule);

        try {
            $result = $service->convertir($propositionVehicule, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['conversion' => $e->getMessage()]);
        }

        $immat = $result['vehicule']->immatriculation;
        $msg = $result['proprietaire_existant']
            ? "Proposition convertie. Véhicule {$immat} créé et lié au propriétaire existant."
            : "Proposition convertie. Nouveau propriétaire et véhicule {$immat} créés.";

        return redirect()
            ->route('propositions-vehicules.index')
            ->with('success', $msg);
    }
}
