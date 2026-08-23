<?php

namespace App\Http\Controllers\Settings;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\PrestataireType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreCommissionConfigurationRequest;
use App\Http\Requests\Settings\StoreCommissionConsultantAffectationRequest;
use App\Http\Requests\Settings\StoreCommissionRegleRequest;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionConsultantAffectation;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Parametre;
use App\Models\Prestataire;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
                ->with(['consultant.personne', 'consultant.entrepriseTierce'])
                ->get()
            : collect();

        $cibles = [
            ['code' => CommissionCibleType::CODE_PROPRIETAIRE, 'libelle' => 'Propriétaire'],
            ['code' => CommissionCibleType::CODE_EQUIPE_LIVRAISON, 'libelle' => 'Livreur'],
            ['code' => CommissionCibleType::CODE_SITE, 'libelle' => 'Site'],
            ['code' => CommissionCibleType::CODE_CONSULTANT, 'libelle' => 'Consultant'],
        ];

        // Seules les catégories explicitement configurées sont affichées.
        // L'absence d'une ligne signifie « aucune commission pour cette catégorie ».
        $categoriesConfigurees = $reglesActives
            ->filter(fn (CommissionRegle $r) => $r->scope_type->value === 'categorie' && $r->scope_id !== null)
            ->pluck('scope_id')
            ->unique();

        $lignes = $categories
            ->filter(fn (Categorie $c) => $categoriesConfigurees->contains($c->id))
            ->map(fn (Categorie $c) => [
                'scope_type' => 'categorie',
                'scope_id' => $c->id,
                'libelle' => $c->nom,
                'montants' => $this->montantsPour($reglesActives, 'categorie', $c->id, $cibles),
            ])
            ->values()
            ->all();

        return Inertia::render('settings/CommissionRegles/Index', [
            'lignes' => $lignes,
            'categories' => $categories->map(fn (Categorie $categorie) => [
                'value' => $categorie->id,
                'label' => $categorie->nom,
            ])->values(),
            'cibles' => $cibles,
            'consultantActifId' => CommissionConsultantAffectation::actifPour($orgId)?->prestataire_id,
            'consultantsEligibles' => Prestataire::where('organization_id', $orgId)
                ->actifs()
                ->parType(PrestataireType::CONSULTANT)
                ->with(['personne', 'entrepriseTierce'])
                ->get()
                ->map(fn (Prestataire $p) => [
                    'value' => $p->id,
                    'label' => $p->nom_complet ?? $p->reference,
                ])
                ->values(),
        ]);
    }

    /**
     * Enregistre atomiquement toute la configuration visible après confirmation :
     * consultant, catégories autorisées et montants des quatre bénéficiaires.
     */
    public function storeConfiguration(StoreCommissionConfigurationRequest $request): RedirectResponse
    {
        $this->authorize('create', CommissionRegle::class);

        $orgId = auth()->user()->organization_id;
        $data = $request->validated();
        $today = Carbon::today()->toDateString();

        DB::transaction(function () use ($orgId, $data, $today): void {
            $processus = CommissionProcessus::firstOrCreate(
                ['organization_id' => $orgId, 'code' => CommissionProcessus::CODE_VENTE],
                [
                    'libelle' => 'Vente',
                    'declencheur' => Parametre::getDeclencheurCommissionVente($orgId)->value,
                    'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
                    'statut' => CommissionActivationStatut::ACTIF->value,
                ],
            );

            $categorieIds = collect($data['lignes'])->pluck('categorie_id')->all();
            $hier = Carbon::parse($today)->subDay()->toDateString();

            // Retirer une ligne retire réellement son droit à commission. Les anciennes
            // règles globales sont closes pour éviter tout repli silencieux.
            CommissionRegle::where('organization_id', $orgId)
                ->where('processus_id', $processus->id)
                ->where('unite_calcul', CommissionUniteCalcul::PAR_UNITE_VENDUE->value)
                ->where('statut', CommissionRegleStatut::ACTIVE->value)
                ->where(function ($query) use ($categorieIds): void {
                    $query->where('scope_type', 'global')
                        ->orWhere(function ($categoryQuery) use ($categorieIds): void {
                            $categoryQuery->where('scope_type', 'categorie')
                                ->whereNotIn('scope_id', $categorieIds);
                        });
                })
                ->update([
                    'effective_to' => $hier,
                    'statut' => CommissionRegleStatut::REMPLACEE->value,
                ]);

            $cibleTypes = [
                CommissionCibleType::CODE_PROPRIETAIRE,
                CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                CommissionCibleType::CODE_SITE,
                CommissionCibleType::CODE_CONSULTANT,
            ];

            foreach ($data['lignes'] as $ligne) {
                foreach ($cibleTypes as $cibleType) {
                    $this->enregistrerRegleCategorie(
                        $orgId,
                        $processus,
                        $ligne['categorie_id'],
                        $cibleType,
                        (int) $ligne['montants'][$cibleType],
                        $today,
                        $cibleType === CommissionCibleType::CODE_CONSULTANT
                            ? $ligne['consultant_id']
                            : null,
                    );
                }
            }
        });

        return back()->with('success', 'Configuration des commissions enregistrée.');
    }

    private function enregistrerRegleCategorie(
        string $orgId,
        CommissionProcessus $processus,
        string $categorieId,
        string $cibleType,
        int $montant,
        string $effectiveFrom,
        ?string $consultantId = null,
    ): void {
        $ancienne = CommissionRegle::where('organization_id', $orgId)
            ->where('processus_id', $processus->id)
            ->where('cible_type', $cibleType)
            ->where('scope_type', 'categorie')
            ->where('scope_id', $categorieId)
            ->where('unite_calcul', CommissionUniteCalcul::PAR_UNITE_VENDUE->value)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->first();

        if ($ancienne
            && (int) $ancienne->montant === $montant
            && $ancienne->consultant_id === $consultantId) {
            return;
        }

        $mode = in_array($cibleType, [
            CommissionCibleType::CODE_PROPRIETAIRE,
            CommissionCibleType::CODE_SITE,
            CommissionCibleType::CODE_CONSULTANT,
        ], true) ? CommissionMode::DIRECT : CommissionMode::A_REPARTIR;

        CommissionRegle::create([
            'organization_id' => $orgId,
            'processus_id' => $processus->id,
            'libelle' => $this->libelleAuto($cibleType, 'categorie', $categorieId),
            'scope_type' => 'categorie',
            'scope_id' => $categorieId,
            'cible_type' => $cibleType,
            'mode' => $mode->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'consultant_id' => $consultantId,
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
    }

    /**
     * Désigne le prestataire actuellement bénéficiaire de la cible "consultant" — jamais un
     * prestataire codé en dur. Même principe de versionnement que store() ci-dessus : l'ancienne
     * désignation n'est jamais modifiée en place, elle est close puis remplacée, pour que les
     * commissions déjà générées gardent leur bénéficiaire d'origine.
     */
    public function updateConsultant(StoreCommissionConsultantAffectationRequest $request): RedirectResponse
    {
        $this->authorize('create', CommissionRegle::class);

        $orgId = auth()->user()->organization_id;
        $prestataireId = $request->validated('prestataire_id');
        $today = Carbon::today()->toDateString();

        $ancienne = CommissionConsultantAffectation::actifPour($orgId);

        if ($ancienne && $ancienne->prestataire_id === $prestataireId) {
            return back();
        }

        CommissionConsultantAffectation::create([
            'organization_id' => $orgId,
            'prestataire_id' => $prestataireId,
            'effective_from' => $today,
            'remplace_affectation_id' => $ancienne?->id,
            'statut' => CommissionRegleStatut::ACTIVE->value,
            'created_by' => auth()->id(),
        ]);

        if ($ancienne) {
            $ancienne->update([
                'effective_to' => Carbon::parse($today)->subDay()->toDateString(),
                'statut' => CommissionRegleStatut::REMPLACEE->value,
            ]);
        }

        return back()->with('success', 'Consultant bénéficiaire mis à jour.');
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
                'consultant_id' => $regle->consultant_id,
                'consultant_label' => $regle->consultant?->nom_complet ?? $regle->consultant?->reference,
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

        // DIRECT pour Propriétaire, Site ET Consultant : un seul bénéficiaire déterministe,
        // jamais de répartition à calculer (cf. CommissionEnveloppeGenerator, branches CODE_SITE
        // et CODE_CONSULTANT).
        $mode = in_array($data['cible_type'], [
            CommissionCibleType::CODE_PROPRIETAIRE,
            CommissionCibleType::CODE_SITE,
            CommissionCibleType::CODE_CONSULTANT,
        ], true)
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
            CommissionCibleType::CODE_CONSULTANT => 'Consultant',
            default => 'Livreur',
        };
        $scopeLabel = $scopeType === 'global'
            ? 'toutes catégories'
            : (Categorie::find($scopeId)?->nom ?? 'catégorie');

        return "{$cibleLabel} — {$scopeLabel}";
    }
}
