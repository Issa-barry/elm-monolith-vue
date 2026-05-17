<?php

namespace App\Http\Controllers;

use App\Enums\StatutPeriodePaie;
use App\Models\PaiePeriode;
use App\Services\PaieCalculService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaieController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PaiePeriode::class);

        $orgId = $request->user()->organization_id;

        $periodes = PaiePeriode::where('organization_id', $orgId)
            ->when($request->filled('annee'), fn ($q) => $q->where('annee', $request->annee))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->statut))
            ->withCount('lignes')
            ->orderByDesc('annee')
            ->orderByDesc('mois')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($p) => [
                'id' => $p->id,
                'mois' => $p->mois,
                'annee' => $p->annee,
                'label' => $p->labelPeriode(),
                'statut' => $p->statut->value,
                'statut_label' => $p->statut->label(),
                'lignes_count' => $p->lignes_count,
                'notes' => $p->notes,
                'created_at' => $p->created_at->format('Y-m-d'),
            ]);

        return Inertia::render('Paie/Index', [
            'periodes' => $periodes,
            'filters' => $request->only(['annee', 'statut']),
            'statut_options' => StatutPeriodePaie::options(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', PaiePeriode::class);

        return Inertia::render('Paie/Create', [
            'mois_courant' => now()->month,
            'annee_courante' => now()->year,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PaiePeriode::class);

        $orgId = $request->user()->organization_id;

        $data = $request->validate([
            'mois' => ['required', 'integer', 'min:1', 'max:12'],
            'annee' => ['required', 'integer', 'min:2020', 'max:2099'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['mois'] = (int) $data['mois'];
        $data['annee'] = (int) $data['annee'];

        $existe = PaiePeriode::where('organization_id', $orgId)
            ->where('mois', $data['mois'])
            ->where('annee', $data['annee'])
            ->exists();

        if ($existe) {
            return back()->withErrors(['mois' => 'Une période de paie existe déjà pour ce mois et cette année.']);
        }

        $periode = PaiePeriode::create(array_merge($data, [
            'organization_id' => $orgId,
            'statut' => StatutPeriodePaie::BROUILLON,
        ]));

        return redirect()->route('paie.show', $periode)
            ->with('success', 'Période de paie créée.');
    }

    public function show(Request $request, PaiePeriode $paie): Response
    {
        $this->authorize('view', $paie);

        $paie->load([
            'lignes.employe:id,nom,prenom,matricule',
            'lignes.variables',
            'lignes.paiements',
        ]);

        $lignes = $paie->lignes->map(fn ($l) => [
            'id' => $l->id,
            'employe_id' => $l->employe_id,
            'employe_nom' => $l->employe?->nom_complet ?? '—',
            'employe_matricule' => $l->employe?->matricule ?? '—',
            'salaire_base' => (float) $l->salaire_base,
            'jours_travailles' => (float) $l->jours_travailles,
            'jours_periode' => $l->jours_periode,
            'total_primes' => (float) $l->total_primes,
            'total_autres_gains' => (float) $l->total_autres_gains,
            'total_avances' => (float) $l->total_avances,
            'total_retenues' => (float) $l->total_retenues,
            'total_absences' => (float) $l->total_absences,
            'total_autres_deductions' => (float) $l->total_autres_deductions,
            'brut' => (float) $l->brut,
            'deductions' => (float) $l->deductions,
            'net' => (float) $l->net,
            'deja_paye' => (float) $l->deja_paye,
            'reste_a_payer' => (float) $l->reste_a_payer,
            'statut' => $l->statut->value,
            'statut_label' => $l->statut->label(),
            'variables' => $l->variables->map(fn ($v) => [
                'id' => $v->id,
                'type' => $v->type->value,
                'libelle' => $v->libelle,
                'montant' => (float) $v->montant,
                'note' => $v->note,
            ]),
            'paiements' => $l->paiements->map(fn ($p) => [
                'id' => $p->id,
                'montant' => (float) $p->montant,
                'date_paiement' => $p->date_paiement->format('Y-m-d'),
                'mode_paiement' => $p->mode_paiement,
                'note' => $p->note,
            ]),
        ]);

        $transitions = $paie->statut->transitionsAutorisees();

        return Inertia::render('Paie/Show', [
            'periode' => [
                'id' => $paie->id,
                'mois' => $paie->mois,
                'annee' => $paie->annee,
                'label' => $paie->labelPeriode(),
                'statut' => $paie->statut->value,
                'statut_label' => $paie->statut->label(),
                'est_verrouille' => $paie->statut->estVerrouille(),
                'notes' => $paie->notes,
            ],
            'lignes' => $lignes,
            'transitions' => array_map(fn ($t) => $t->value, $transitions),
            'can' => [
                'update' => $request->user()->can('update', $paie),
                'validate' => $request->user()->can('validate', $paie),
                'pay' => $request->user()->can('pay', $paie),
                'close' => $request->user()->can('close', $paie),
                'delete' => $request->user()->can('delete', $paie),
            ],
        ]);
    }

    public function calculer(Request $request, PaiePeriode $paie, PaieCalculService $service): RedirectResponse
    {
        $this->authorize('update', $paie);

        if (! $paie->statut->peutTransitionnerVers(StatutPeriodePaie::CALCULE)) {
            return back()->withErrors(['statut' => 'Transition non autorisée depuis le statut actuel.']);
        }

        $service->genererLignes($paie);
        $service->calculerPeriode($paie);

        $paie->update(['statut' => StatutPeriodePaie::CALCULE]);

        return back()->with('success', 'Période calculée.');
    }

    public function valider(PaiePeriode $paie): RedirectResponse
    {
        $this->authorize('validate', $paie);

        if (! $paie->statut->peutTransitionnerVers(StatutPeriodePaie::VALIDE_RH)) {
            return back()->withErrors(['statut' => 'Transition non autorisée depuis le statut actuel.']);
        }

        $paie->update(['statut' => StatutPeriodePaie::VALIDE_RH]);

        return back()->with('success', 'Période validée RH.');
    }

    public function marquerPaye(PaiePeriode $paie): RedirectResponse
    {
        $this->authorize('pay', $paie);

        if (! $paie->statut->peutTransitionnerVers(StatutPeriodePaie::PAYE)) {
            return back()->withErrors(['statut' => 'Transition non autorisée depuis le statut actuel.']);
        }

        $paie->update(['statut' => StatutPeriodePaie::PAYE]);

        return back()->with('success', 'Période marquée comme payée.');
    }

    public function cloturer(PaiePeriode $paie): RedirectResponse
    {
        $this->authorize('close', $paie);

        if (! $paie->statut->peutTransitionnerVers(StatutPeriodePaie::CLOTURE)) {
            return back()->withErrors(['statut' => 'Transition non autorisée depuis le statut actuel.']);
        }

        $paie->update(['statut' => StatutPeriodePaie::CLOTURE]);

        return back()->with('success', 'Période clôturée.');
    }

    public function destroy(PaiePeriode $paie): RedirectResponse
    {
        $this->authorize('delete', $paie);

        $paie->delete();

        return redirect()->route('paie.index')->with('success', 'Période supprimée.');
    }
}
