<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\TypeSupportTresorerie;
use App\Http\Controllers\Controller;
use App\Models\CompteComptable;
use App\Models\CompteMapping;
use App\Models\CompteTresorerie;
use App\Models\Site;
use App\Services\Comptabilite\SupportTresorerieTypeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    public function index(SupportTresorerieTypeResolver $typeResolver): Response
    {
        abort_unless(auth()->user()->can('tresorerie.gerer_soldes_ouverture'), 403);

        $orgId = auth()->user()->organization_id;
        $typesParCompte = $typeResolver->typesParCompte($orgId);

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
                'compte_comptable_id' => $c->compte_comptable_id,
                'compte_numero' => $c->compte?->numero,
                'moyen_paiement_defaut' => $c->moyen_paiement_defaut,
                'actif' => $c->actif,
                'solde_ouverture' => $c->soldeOuverture ? [
                    'id' => $c->soldeOuverture->id,
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
                ->get(['id', 'numero', 'libelle'])
                ->map(fn (CompteComptable $c) => [
                    'id' => $c->id,
                    'numero' => $c->numero,
                    'libelle' => $c->libelle,
                    'type_support' => $typesParCompte->get($c->id)?->value,
                ]),
        ]);
    }

    public function store(Request $request, SupportTresorerieTypeResolver $typeResolver)
    {
        abort_unless(auth()->user()->can('tresorerie.gerer_soldes_ouverture'), 403);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'site_id' => ['required', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'compte_comptable_id' => ['required', Rule::exists('compta_comptes', 'id')->where('organization_id', $orgId)],
            'type' => ['required', Rule::enum(TypeSupportTresorerie::class)],
            // Facultatif : généré automatiquement ("{Type} de {Site}") par
            // CompteTresorerie::boot() si laissé vide — cf. revue du 2026-08-22.
            'libelle' => ['nullable', 'string', 'max:150'],
            'moyen_paiement_defaut' => ['nullable', 'string', 'max:30'],
        ]);

        // Cohérence type ↔ compte comptable (ex: refuser Caisse + 561300 Mobile
        // Money) — déduite de compta_mappings, jamais d'un numéro codé en dur.
        // Un compte non reconnu (type déduit null) est toléré : on ne bloque que
        // les incompatibilités connues avec certitude.
        $typeAttendu = $typeResolver->typePourCompte($orgId, $data['compte_comptable_id']);
        $typeSaisi = TypeSupportTresorerie::from($data['type']);

        if ($typeAttendu !== null && $typeAttendu !== $typeSaisi) {
            throw ValidationException::withMessages([
                'compte_comptable_id' => "Ce compte comptable correspond au type « {$typeAttendu->label()} », pas « {$typeSaisi->label()} ».",
            ]);
        }

        CompteTresorerie::create([...$data, 'organization_id' => $orgId, 'actif' => true]);

        return back()->with('success', 'Support de trésorerie créé.');
    }

    public function update(Request $request, CompteTresorerie $compteTresorerie, SupportTresorerieTypeResolver $typeResolver)
    {
        abort_unless(auth()->user()->can('tresorerie.gerer_soldes_ouverture'), 403);
        abort_unless($compteTresorerie->organization_id === auth()->user()->organization_id, 403);

        $orgId = $compteTresorerie->organization_id;

        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(TypeSupportTresorerie::class)],
            'compte_comptable_id' => ['required', Rule::exists('compta_comptes', 'id')->where('organization_id', $orgId)],
            'moyen_paiement_defaut' => ['nullable', 'string', 'max:30'],
            'actif' => ['required', 'boolean'],
        ]);

        $typeOuCompteChange = $data['type'] !== $compteTresorerie->type->value
            || $data['compte_comptable_id'] !== $compteTresorerie->compte_comptable_id;

        // Une fois un solde d'ouverture saisi, le type et le compte comptable sont
        // figés : les écritures déjà posées référencent ce compte précis, les
        // modifier après coup les rendrait incohérentes silencieusement.
        if ($typeOuCompteChange && $compteTresorerie->soldeOuverture !== null) {
            throw ValidationException::withMessages([
                'type' => "Le type et le compte comptable ne peuvent plus être modifiés : un solde d'ouverture existe déjà pour ce support.",
            ]);
        }

        $typeAttendu = $typeResolver->typePourCompte($orgId, $data['compte_comptable_id']);
        $typeSaisi = TypeSupportTresorerie::from($data['type']);

        if ($typeAttendu !== null && $typeAttendu !== $typeSaisi) {
            throw ValidationException::withMessages([
                'compte_comptable_id' => "Ce compte comptable correspond au type « {$typeAttendu->label()} », pas « {$typeSaisi->label()} ».",
            ]);
        }

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
