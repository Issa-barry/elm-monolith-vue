<?php

namespace App\Http\Controllers\Settings;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreCommissionRegleRequest;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Parametre;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres → Commissions (Phase 2, cf. conception cible §I). Gère les
 * barèmes fixes PAR_UNITE_VENDUE par catégorie/cible — jamais MARGE_OPERATION
 * (pont Phase 1, strictement interne, jamais exposé ici).
 *
 * Une "modification" n'écrase jamais une règle existante : elle clôture la
 * version active (`effective_to`) et crée une nouvelle ligne (`remplace_regle_id`)
 * — cf. décision AMOA "aucune modification rétroactive".
 */
class CommissionRegleController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', CommissionRegle::class);

        $orgId = auth()->user()->organization_id;

        $categories = Categorie::where('organization_id', $orgId)
            ->where('statut', 'actif')
            ->orderBy('position')
            ->orderBy('nom')
            ->get(['id', 'nom']);

        // Lecture pure : ne crée jamais le processus ici (effet de bord indésirable
        // sur un GET) — seul store() le crée, à l'enregistrement du premier barème.
        $processus = CommissionProcessus::where('organization_id', $orgId)
            ->where('code', CommissionProcessus::CODE_VENTE)
            ->first();

        $reglesActives = $processus
            ? CommissionRegle::where('organization_id', $orgId)
                ->where('processus_id', $processus->id)
                ->where('unite_calcul', CommissionUniteCalcul::PAR_UNITE_VENDUE->value)
                ->where('statut', CommissionRegleStatut::ACTIVE->value)
                ->get()
            : collect();

        $cibles = [
            ['code' => CommissionCibleType::CODE_PROPRIETAIRE, 'libelle' => 'Propriétaire'],
            ['code' => CommissionCibleType::CODE_EQUIPE_LIVRAISON, 'libelle' => 'Livraison'],
            ['code' => CommissionCibleType::CODE_SITE, 'libelle' => 'Site'],
        ];

        $lignes = [
            [
                'scope_type' => 'global',
                'scope_id' => null,
                'libelle' => 'Toutes catégories (par défaut)',
                'montants' => $this->montantsPour($reglesActives, 'global', null, $cibles),
            ],
            ...$categories->map(fn (Categorie $c) => [
                'scope_type' => 'categorie',
                'scope_id' => $c->id,
                'libelle' => $c->nom,
                'montants' => $this->montantsPour($reglesActives, 'categorie', $c->id, $cibles),
            ])->all(),
        ];

        return Inertia::render('settings/CommissionRegles/Index', [
            'lignes' => $lignes,
            'cibles' => $cibles,
        ]);
    }

    private function montantsPour($reglesActives, string $scopeType, ?string $scopeId, array $cibles): array
    {
        $montants = [];
        foreach ($cibles as $cible) {
            $regle = $reglesActives->first(fn (CommissionRegle $r) => $r->scope_type->value === $scopeType
                && $r->scope_id === $scopeId
                && $r->cible_type === $cible['code']
            );
            $montants[$cible['code']] = $regle ? [
                'montant' => (float) $regle->montant,
                'effective_from' => $regle->effective_from->toDateString(),
                'regle_id' => $regle->id,
            ] : null;
        }

        return $montants;
    }

    public function store(StoreCommissionRegleRequest $request): RedirectResponse
    {
        $this->authorize('create', CommissionRegle::class);

        $orgId = auth()->user()->organization_id;
        $data = $request->validated();

        $processus = CommissionProcessus::firstOrCreate(
            ['organization_id' => $orgId, 'code' => CommissionProcessus::CODE_VENTE],
            [
                'libelle' => 'Vente',
                'declencheur' => Parametre::getDeclencheurCommissionVente($orgId)->value,
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
                'statut' => CommissionActivationStatut::ACTIF->value,
            ],
        );

        $scopeType = $data['scope_type'];
        $scopeId = $scopeType === 'categorie' ? $data['categorie_id'] : null;
        $effectiveFrom = $data['effective_from'] ?? Carbon::today()->toDateString();

        // DIRECT pour Propriétaire ET Site : un seul bénéficiaire déterministe, jamais de
        // répartition à calculer (cf. CommissionEnveloppeGenerator, branche CODE_SITE).
        $mode = in_array($data['cible_type'], [CommissionCibleType::CODE_PROPRIETAIRE, CommissionCibleType::CODE_SITE], true)
            ? CommissionMode::DIRECT
            : CommissionMode::A_REPARTIR;

        $ancienne = CommissionRegle::where('organization_id', $orgId)
            ->where('processus_id', $processus->id)
            ->where('cible_type', $data['cible_type'])
            ->where('scope_type', $scopeType)
            ->when($scopeId, fn ($q) => $q->where('scope_id', $scopeId), fn ($q) => $q->whereNull('scope_id'))
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->first();

        $nouvelle = CommissionRegle::create([
            'organization_id' => $orgId,
            'processus_id' => $processus->id,
            'libelle' => $this->libelleAuto($data['cible_type'], $scopeType, $scopeId),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'cible_type' => $data['cible_type'],
            'mode' => $mode->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $data['montant'],
            'effective_from' => $effectiveFrom,
            'remplace_regle_id' => $ancienne?->id,
            'statut' => CommissionRegleStatut::ACTIVE->value,
            'created_by' => auth()->id(),
        ]);

        if ($ancienne) {
            $ancienne->update([
                'effective_to' => Carbon::parse($effectiveFrom)->subDay()->toDateString(),
                'statut' => CommissionRegleStatut::REMPLACEE->value,
            ]);
        }

        return back()->with('success', 'Barème enregistré.');
    }

    private function libelleAuto(string $cibleType, string $scopeType, ?string $scopeId): string
    {
        $cibleLabel = match ($cibleType) {
            CommissionCibleType::CODE_PROPRIETAIRE => 'Propriétaire',
            CommissionCibleType::CODE_SITE => 'Site',
            default => 'Livraison',
        };
        $scopeLabel = $scopeType === 'global'
            ? 'toutes catégories'
            : (Categorie::find($scopeId)?->nom ?? 'catégorie');

        return "{$cibleLabel} — {$scopeLabel}";
    }
}
