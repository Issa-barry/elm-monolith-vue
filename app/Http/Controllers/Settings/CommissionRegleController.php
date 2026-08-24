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
use App\Models\TypeVehicule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 *
 * Depuis la refonte 2026-08-24 : un bénéficiaire non coché n'a simplement pas de
 * règle active (jamais un montant à 0) et un barème peut être décliné par type de
 * véhicule (montant standard obligatoire + exceptions optionnelles), cf.
 * CommissionRegleResolver.
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

        $typesVehicules = TypeVehicule::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get(['id', 'nom']);

        // Lecture pure : ne crée jamais le processus ici (effet de bord indésirable
        // sur un GET) — seul store()/storeConfiguration() le crée, à l'enregistrement
        // du premier barème.
        $processus = CommissionProcessus::where('organization_id', $orgId)
            ->where('code', CommissionProcessus::CODE_VENTE)
            ->first();

        $reglesActives = $processus
            ? CommissionRegle::where('organization_id', $orgId)
                ->where('processus_id', $processus->id)
                ->where('unite_calcul', CommissionUniteCalcul::PAR_UNITE_VENDUE->value)
                ->where('statut', CommissionRegleStatut::ACTIVE->value)
                ->with(['consultant.personne', 'consultant.entrepriseTierce', 'typeVehicule'])
                ->get()
            : collect();

        $cibles = [
            ['code' => CommissionCibleType::CODE_PROPRIETAIRE, 'libelle' => 'Propriétaire'],
            ['code' => CommissionCibleType::CODE_EQUIPE_LIVRAISON, 'libelle' => 'Livreur'],
            ['code' => CommissionCibleType::CODE_SITE, 'libelle' => 'Site'],
            ['code' => CommissionCibleType::CODE_CONSULTANT, 'libelle' => 'Consultant'],
        ];

        // Seules les catégories ayant au moins une règle catégorie active sont
        // candidates à l'affichage — la présence effective d'au moins un
        // bénéficiaire (montant > 0) est vérifiée ensuite dans
        // configurationPourCategorie(), pour lire correctement les anciennes
        // règles à 0 comme "aucun bénéficiaire".
        $categoriesConfigurees = $reglesActives
            ->filter(fn (CommissionRegle $r) => $r->scope_type->value === 'categorie' && $r->scope_id !== null)
            ->pluck('scope_id')
            ->unique();

        $lignes = $categories
            ->filter(fn (Categorie $c) => $categoriesConfigurees->contains($c->id))
            ->map(fn (Categorie $c) => array_merge(
                [
                    'scope_type' => 'categorie',
                    'scope_id' => $c->id,
                    'libelle' => $c->nom,
                ],
                $this->configurationPourCategorie($reglesActives, $c->id, $cibles),
            ))
            ->filter(fn (array $ligne) => ! empty($ligne['beneficiaires']))
            ->values()
            ->all();

        return Inertia::render('settings/CommissionRegles/Index', [
            'lignes' => $lignes,
            'categories' => $categories->map(fn (Categorie $categorie) => [
                'value' => $categorie->id,
                'label' => $categorie->nom,
            ])->values(),
            'cibles' => $cibles,
            'typesVehicules' => $typesVehicules->map(fn (TypeVehicule $t) => [
                'value' => $t->id,
                'label' => $t->nom,
            ])->values(),
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
     * Regroupe les règles actives d'une catégorie en bénéficiaires cochés (montant
     * standard, type_vehicule_id NULL, montant > 0) + exceptions par type de
     * véhicule (montant > 0, ne conserve que les cibles toujours bénéficiaires).
     * Une ancienne règle à 0 n'est jamais remontée : elle se lit comme "aucun
     * bénéficiaire" (lecture seule, aucune migration destructive).
     */
    private function configurationPourCategorie(Collection $reglesActives, string $categorieId, array $cibles): array
    {
        $reglesCategorie = $reglesActives->filter(
            fn (CommissionRegle $r) => $r->scope_type->value === 'categorie' && $r->scope_id === $categorieId
        );

        $standards = $reglesCategorie->filter(fn (CommissionRegle $r) => $r->type_vehicule_id === null);

        $beneficiaires = [];
        $montantsStandard = [];
        $consultantId = null;
        $consultantLabel = null;

        foreach ($cibles as $cible) {
            $regle = $standards->first(fn (CommissionRegle $r) => $r->cible_type === $cible['code']);
            if (! $regle || (float) $regle->montant <= 0) {
                continue;
            }

            $beneficiaires[] = $cible['code'];
            $montantsStandard[$cible['code']] = [
                'montant' => (float) $regle->montant,
                'effective_from' => $regle->effective_from->toDateString(),
                'regle_id' => $regle->id,
            ];

            if ($cible['code'] === CommissionCibleType::CODE_CONSULTANT) {
                $consultantId = $regle->consultant_id;
                $consultantLabel = $regle->consultant?->nom_complet ?? $regle->consultant?->reference;
            }
        }

        $exceptions = $reglesCategorie
            ->filter(fn (CommissionRegle $r) => $r->type_vehicule_id !== null
                && (float) $r->montant > 0
                && in_array($r->cible_type, $beneficiaires, true)
            )
            ->groupBy('type_vehicule_id')
            ->map(function (Collection $regles, string $typeVehiculeId) {
                $montants = [];
                foreach ($regles as $regle) {
                    $montants[$regle->cible_type] = [
                        'montant' => (float) $regle->montant,
                        'effective_from' => $regle->effective_from->toDateString(),
                        'regle_id' => $regle->id,
                    ];
                }

                return [
                    'type_vehicule_id' => $typeVehiculeId,
                    'type_vehicule_label' => $regles->first()->typeVehicule?->nom ?? '—',
                    'montants' => $montants,
                ];
            })
            ->values()
            ->all();

        return [
            'beneficiaires' => $beneficiaires,
            'montants_standard' => $montantsStandard,
            'consultant_id' => $consultantId,
            'consultant_label' => $consultantLabel,
            'exceptions' => $exceptions,
        ];
    }

    /**
     * Enregistre atomiquement toute la configuration visible après confirmation :
     * bénéficiaires cochés, consultant, montants standards et exceptions par type
     * de véhicule. Une catégorie absente du payload voit toutes ses règles closes
     * (retrait complet) ; c'est aussi le mécanisme utilisé par le bouton
     * "Supprimer" du front, qui renvoie la configuration complète moins la
     * catégorie retirée.
     */
    public function storeConfiguration(StoreCommissionConfigurationRequest $request): RedirectResponse
    {
        $this->authorize('create', CommissionRegle::class);

        $orgId = auth()->user()->organization_id;
        $data = $request->validated();
        $today = Carbon::today()->toDateString();
        $hier = Carbon::parse($today)->subDay()->toDateString();

        DB::transaction(function () use ($orgId, $data, $today, $hier): void {
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

            // Retirer une catégorie retire réellement son droit à commission (toutes
            // cibles et tous types de véhicule confondus). Les anciennes règles
            // globales legacy sont closes pour éviter tout repli silencieux.
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

            foreach ($data['lignes'] as $ligne) {
                $this->enregistrerConfigurationCategorie($orgId, $processus, $ligne, $today);
            }
        });

        return back()->with('success', 'Configuration des commissions enregistrée.');
    }

    /**
     * Traduit une ligne du payload (bénéficiaires + montants standard + exceptions
     * véhicule) en un ensemble désiré de règles (cible_type, type_vehicule_id) et
     * fait converger l'état actif de la catégorie vers cet ensemble : ferme ce qui
     * n'est plus désiré, verse (no-op si inchangé, sinon clôture + nouvelle
     * version) ce qui l'est. Une exception dont le montant est identique au
     * standard soumis n'est jamais persistée (pas d'exception inutile).
     */
    private function enregistrerConfigurationCategorie(
        string $orgId,
        CommissionProcessus $processus,
        array $ligne,
        string $today,
    ): void {
        $categorieId = $ligne['categorie_id'];
        $beneficiaires = $ligne['beneficiaires'];
        $montantsStandard = $ligne['montants_standard'];
        $consultantId = in_array(CommissionCibleType::CODE_CONSULTANT, $beneficiaires, true)
            ? $ligne['consultant_id']
            : null;

        // clé interne 'std' = barème standard (type_vehicule_id NULL).
        $desired = [];
        foreach ($beneficiaires as $cibleType) {
            $desired[$cibleType]['std'] = (int) $montantsStandard[$cibleType];
        }
        foreach ($ligne['exceptions'] ?? [] as $exception) {
            $typeVehiculeId = $exception['type_vehicule_id'];
            foreach ($exception['montants'] ?? [] as $cibleType => $montant) {
                if (! in_array($cibleType, $beneficiaires, true)) {
                    continue;
                }
                $montant = (int) $montant;
                if ($montant === (int) $montantsStandard[$cibleType]) {
                    continue;
                }
                $desired[$cibleType][$typeVehiculeId] = $montant;
            }
        }

        $reglesActuelles = CommissionRegle::where('organization_id', $orgId)
            ->where('processus_id', $processus->id)
            ->where('scope_type', 'categorie')
            ->where('scope_id', $categorieId)
            ->where('unite_calcul', CommissionUniteCalcul::PAR_UNITE_VENDUE->value)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->get();

        $idsAFermer = $reglesActuelles
            ->reject(fn (CommissionRegle $r) => isset($desired[$r->cible_type][$r->type_vehicule_id ?? 'std']))
            ->pluck('id');

        if ($idsAFermer->isNotEmpty()) {
            CommissionRegle::whereIn('id', $idsAFermer)->update([
                'effective_to' => Carbon::parse($today)->subDay()->toDateString(),
                'statut' => CommissionRegleStatut::REMPLACEE->value,
            ]);
        }

        foreach ($desired as $cibleType => $parVehicule) {
            foreach ($parVehicule as $cle => $montant) {
                $this->enregistrerRegleCategorie(
                    $orgId,
                    $processus,
                    $categorieId,
                    $cibleType,
                    $montant,
                    $today,
                    $cibleType === CommissionCibleType::CODE_CONSULTANT ? $consultantId : null,
                    $cle === 'std' ? null : $cle,
                );
            }
        }
    }

    private function enregistrerRegleCategorie(
        string $orgId,
        CommissionProcessus $processus,
        string $categorieId,
        string $cibleType,
        int $montant,
        string $effectiveFrom,
        ?string $consultantId = null,
        ?string $typeVehiculeId = null,
    ): void {
        $ancienne = CommissionRegle::where('organization_id', $orgId)
            ->where('processus_id', $processus->id)
            ->where('cible_type', $cibleType)
            ->where('scope_type', 'categorie')
            ->where('scope_id', $categorieId)
            ->where('unite_calcul', CommissionUniteCalcul::PAR_UNITE_VENDUE->value)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->when(
                $typeVehiculeId === null,
                fn ($q) => $q->whereNull('type_vehicule_id'),
                fn ($q) => $q->where('type_vehicule_id', $typeVehiculeId),
            )
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
            'libelle' => $this->libelleAuto($cibleType, 'categorie', $categorieId, $typeVehiculeId),
            'scope_type' => 'categorie',
            'scope_id' => $categorieId,
            'type_vehicule_id' => $typeVehiculeId,
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
     *
     * Repli legacy uniquement : depuis la refonte 2026-08-24, le consultant se désigne par
     * catégorie via storeConfiguration(). Conservé pour les organisations n'ayant pas encore
     * migré leurs règles historiques.
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

    private function libelleAuto(string $cibleType, string $scopeType, ?string $scopeId, ?string $typeVehiculeId = null): string
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

        $libelle = "{$cibleLabel} — {$scopeLabel}";

        if ($typeVehiculeId) {
            $vehiculeNom = TypeVehicule::find($typeVehiculeId)?->nom ?? 'véhicule';
            $libelle .= " ({$vehiculeNom})";
        }

        return $libelle;
    }
}
