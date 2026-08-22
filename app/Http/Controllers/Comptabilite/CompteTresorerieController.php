<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\TypeSupportTresorerie;
use App\Http\Controllers\Controller;
use App\Models\CompteComptable;
use App\Models\CompteMapping;
use App\Models\CompteTresorerie;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuration des supports de trésorerie (caisse/banque/mobile money par
 * site) — prérequis pour créer un mouvement de fonds ou un solde d'ouverture.
 * Réservé aux mêmes permissions que la gestion des soldes d'ouverture
 * (opération de configuration rare, pas un écran opérationnel quotidien).
 */
class CompteTresorerieController extends Controller
{
    public function index(): Response
    {
        abort_unless(auth()->user()->can('tresorerie.gerer_soldes_ouverture'), 403);

        $orgId = auth()->user()->organization_id;

        $comptes = CompteTresorerie::forOrg($orgId)
            ->with(['site:id,nom', 'compte:id,numero,libelle', 'soldeOuverture'])
            ->get()
            ->map(fn (CompteTresorerie $c) => [
                'id' => $c->id,
                'site' => $c->site?->nom,
                'site_id' => $c->site_id,
                'type' => $c->type->value,
                'type_label' => $c->type->label(),
                'libelle' => $c->libelle,
                'compte_numero' => $c->compte?->numero,
                'moyen_paiement_defaut' => $c->moyen_paiement_defaut,
                'actif' => $c->actif,
                'solde_ouverture' => $c->soldeOuverture ? [
                    'montant' => (float) $c->soldeOuverture->montant,
                    'statut' => $c->soldeOuverture->statut->value,
                ] : null,
            ]);

        return Inertia::render('Comptabilite/Tresorerie/Supports/Index', [
            'comptes' => $comptes,
            'sites' => Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom']),
            'type_options' => TypeSupportTresorerie::options(),
            'comptes_comptables' => CompteComptable::where('organization_id', $orgId)
                ->whereIn('id', $this->comptesDeTresorerieDisponibles($orgId))
                ->orderBy('numero')
                ->get(['id', 'numero', 'libelle']),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->can('tresorerie.gerer_soldes_ouverture'), 403);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'site_id' => ['required', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'compte_comptable_id' => ['required', Rule::exists('compta_comptes', 'id')->where('organization_id', $orgId)],
            'type' => ['required', Rule::enum(TypeSupportTresorerie::class)],
            'libelle' => ['required', 'string', 'max:150'],
            'moyen_paiement_defaut' => ['nullable', 'string', 'max:30'],
        ]);

        CompteTresorerie::create([...$data, 'organization_id' => $orgId, 'actif' => true]);

        return back()->with('success', 'Support de trésorerie créé.');
    }

    public function update(Request $request, CompteTresorerie $compteTresorerie)
    {
        abort_unless(auth()->user()->can('tresorerie.gerer_soldes_ouverture'), 403);
        abort_unless($compteTresorerie->organization_id === auth()->user()->organization_id, 403);

        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:150'],
            'moyen_paiement_defaut' => ['nullable', 'string', 'max:30'],
            'actif' => ['required', 'boolean'],
        ]);

        $compteTresorerie->update($data);

        return back()->with('success', 'Support de trésorerie mis à jour.');
    }

    /**
     * Comptes éligibles à un support de trésorerie : ceux déjà reconnus comme comptes de
     * trésorerie par le paramétrage propre de l'organisation (rôle "tresorerie" dans
     * compta_mappings), jamais une liste de numéros codée en dur (cf. revue Codex du
     * 2026-08-22 — l'ancienne liste ['571000', '521000', ...] contredisait la promesse
     * "aucun numéro codé en dur" du chantier).
     *
     * @return Collection<int, string>
     */
    private function comptesDeTresorerieDisponibles(string $orgId): Collection
    {
        return CompteMapping::where('organization_id', $orgId)
            ->where('role', 'tresorerie')
            ->pluck('compte_comptable_id')
            ->unique();
    }
}
