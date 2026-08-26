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
 * règle active, et un barème peut être décliné par type de véhicule (montant
 * général pour tous les types, exceptions optionnelles pour certains types, cf.
 * CommissionRegleResolver). Un bénéficiaire COCHÉ peut en revanche avoir un
 * montant général à 0 GNF (2026-08-25) : ce n'est plus ambigu comme avant la
 * refonte, puisque c'est désormais l'existence même de la ligne `beneficiaires`
 * qui distingue "jamais" de "rien par défaut, sauf exception" — un bénéficiaire
 * commissionné uniquement sur certains types de véhicule (ex: Site jamais payé
 * sauf en Tricycle) se configure ainsi : coché, montant général 0, exception
 * positive pour le(s) type(s) concerné(s).
 */
class CommissionRegleController extends Controller
{
    public function redirectConfiguration(): RedirectResponse
    {
        return to_route('settings.commissions.index');
    }

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
        // bénéficiaire (montant général OU une exception strictement positif
        // quelque part) est vérifiée ensuite dans configurationPourCategorie().
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
                $this->configurationPourCategorie($reglesActives, $c->id, $cibles, $typesVehicules),
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
     * Regroupe le barème général (tous types de véhicules) et ses exceptions.
     * Une règle spécifique remplace le montant général uniquement pour son type
     * de véhicule ; les autres types continuent d'utiliser le montant général.
     *
     * Un bénéficiaire coché n'a pas forcément de montant général positif : 0 est
     * une valeur légitime ("rien par défaut"), un droit à commission pouvant
     * n'exister que via une exception pour certains types de véhicule (ex: Site
     * jamais commissionné sauf en Tricycle). L'inclusion dans `beneficiaires`
     * se décide donc sur "au moins UN montant positif quelque part" (général OU
     * une exception), jamais sur le seul montant général — les lectures
     * individuelles (montant général, montant par exception) restent ensuite
     * fidèles à ce qui est réellement en base, 0 compris.
     */
    private function configurationPourCategorie(
        Collection $reglesActives,
        string $categorieId,
        array $cibles,
        Collection $typesVehicules,
    ): array {
        $reglesCategorie = $reglesActives->filter(
            fn (CommissionRegle $r) => $r->scope_type->value === 'categorie' && $r->scope_id === $categorieId
        );

        $standards = $reglesCategorie->filter(fn (CommissionRegle $r) => $r->type_vehicule_id === null);
        $specifiques = $reglesCategorie->filter(fn (CommissionRegle $r) => $r->type_vehicule_id !== null);

        $beneficiaires = [];
        $montantsStandard = [];
        $consultantId = null;
        $consultantLabel = null;

        foreach ($cibles as $cible) {
            $regleStandard = $standards->first(fn (CommissionRegle $r) => $r->cible_type === $cible['code']);
            $exceptionsPourCible = $specifiques->filter(fn (CommissionRegle $r) => $r->cible_type === $cible['code']);

            $standardPositif = $regleStandard && (float) $regleStandard->montant > 0;
            $exceptionPositive = $exceptionsPourCible->contains(fn (CommissionRegle $r) => (float) $r->montant > 0);

            if (! $standardPositif && ! $exceptionPositive) {
                continue;
            }

            $beneficiaires[] = $cible['code'];
            $montantsStandard[$cible['code']] = [
                'montant' => $regleStandard ? (float) $regleStandard->montant : 0.0,
                'effective_from' => $regleStandard?->effective_from?->toDateString() ?? now()->toDateString(),
                'regle_id' => $regleStandard?->id ?? '',
            ];

            if ($cible['code'] === CommissionCibleType::CODE_CONSULTANT) {
                $sourceConsultant = $regleStandard ?? $exceptionsPourCible->first();
                $consultantId = $sourceConsultant?->consultant_id;
                $consultantLabel = $sourceConsultant?->consultant?->nom_complet ?? $sourceConsultant?->consultant?->reference;
            }
        }

        $tarifsVehicules = $specifiques
            ->groupBy('type_vehicule_id')
            ->map(function (Collection $reglesDuType, string $typeVehiculeId) use ($standards, $beneficiaires, $typesVehicules) {
                $montants = [];
                foreach ($beneficiaires as $cibleType) {
                    $regle = $reglesDuType->first(fn (CommissionRegle $r) => $r->cible_type === $cibleType);
                    $source = $regle ?? $standards->first(fn (CommissionRegle $r) => $r->cible_type === $cibleType);
                    $montants[$cibleType] = [
                        'montant' => $source ? (float) $source->montant : 0.0,
                        'effective_from' => $source?->effective_from?->toDateString() ?? now()->toDateString(),
                        'regle_id' => $source?->id ?? '',
                    ];
                }

                return [
                    'type_vehicule_id' => $typeVehiculeId,
                    'type_vehicule_label' => $typesVehicules->firstWhere('id', $typeVehiculeId)?->nom ?? '—',
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
            'exceptions' => $tarifsVehicules,
        ];
    }

    /**
     * Enregistre atomiquement toute la configuration visible après confirmation :
     * bénéficiaires cochés, consultant, barème général et exceptions véhicule.
     * Une catégorie absente du payload voit toutes ses règles closes
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

        return to_route('settings.commissions.index')
            ->with('success', 'Configuration des commissions enregistrée.');
    }

    /**
     * Traduit une ligne du payload en un ensemble désiré de règles
     * (cible_type, type_vehicule_id) et
     * fait converger l'état actif de la catégorie vers cet ensemble : ferme ce qui
     * n'est plus désiré, verse (no-op si inchangé, sinon clôture + nouvelle
     * version) ce qui l'est. Les règles sans type constituent le barème général ;
     * les règles typées sont ses exceptions.
     */
    private function enregistrerConfigurationCategorie(
        string $orgId,
        CommissionProcessus $processus,
        array $ligne,
        string $today,
    ): void {
        $categorieId = $ligne['categorie_id'];
        $beneficiaires = $ligne['beneficiaires'];
        $consultantId = in_array(CommissionCibleType::CODE_CONSULTANT, $beneficiaires, true)
            ? $ligne['consultant_id']
            : null;

        $desired = [];
        foreach ($beneficiaires as $cibleType) {
            $desired[$cibleType]['std'] = (int) $ligne['montants_standard'][$cibleType];
        }
        foreach ($ligne['exceptions'] ?? [] as $tarifVehicule) {
            $typeVehiculeId = $tarifVehicule['type_vehicule_id'];
            foreach ($tarifVehicule['montants'] as $cibleType => $montant) {
                $desired[$cibleType][$typeVehiculeId] = (int) $montant;
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
