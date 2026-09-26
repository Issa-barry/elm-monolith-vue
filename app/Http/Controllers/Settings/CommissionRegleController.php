<?php

namespace App\Http\Controllers\Settings;

use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionUniteCalcul;
use App\Enums\PrestataireType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreCommissionConfigurationRequest;
use App\Http\Requests\Settings\StoreCommissionConsultantAffectationRequest;
use App\Http\Requests\Settings\StoreCommissionRegleRequest;
use App\Models\Categorie;
use App\Models\CommissionBaremeBrouillon;
use App\Models\CommissionCibleType;
use App\Models\CommissionConsultantAffectation;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Prestataire;
use App\Models\TypeVehicule;
use App\Services\Commission\CommissionBaremeConfigurationService;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Commission\ReconfigurationPartagesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    /**
     * Whitelist des processus CONFIGURABLES/ROUTABLES pour toute NOUVELLE opération — source
     * unique consommée par la validation `processus_code` (StoreCommissionConfigurationRequest,
     * EquipeLivraisonController::rules()), les onglets Paramètres > Commissions et la fiche
     * véhicule (VehiculeController::show()).
     *
     * CODE_DISTRIBUTION_CLIENT volontairement absent (décision produit du 02/09/2026) : processus
     * métier réel et courant (chaque distribution génère toujours une CommissionEnveloppe qui LUI
     * est rattachée, pour un reporting distinct de la vente), mais sans onglet de configuration
     * dédié — tant qu'aucune CommissionRegle active ne lui est propre, son barème est résolu par
     * repli automatique sur celui de CODE_LOGISTIQUE_TRANSFERT (cf.
     * CommissionProcessusDefaults::processusResolutionBareme()). Le jour où le métier veut un
     * barème distribution divergent, il suffit d'ajouter ce code ici et de le configurer — aucun
     * changement de code de résolution nécessaire. Le REPORTING (App\Support\Commission\
     * CommissionProcessusFilter, volontairement distinct de cette liste) continue de le distinguer
     * dans tous les cas, configuré ou non.
     *
     * CODE_TRANSFERT_GROSSISTE présent depuis le 05/09/2026 (cf. docs/grossiste.md) : contrairement
     * à distribution_client, ce processus N'A PAS de repli de barème — son onglet de configuration
     * est donc indispensable dès sa création (sans lui, aucun moyen pour l'organisation de le
     * paramétrer), pas un ajout facultatif différé.
     *
     * @return array<string>
     */
    public static function processusCodesDisponibles(): array
    {
        return [
            CommissionProcessus::CODE_VENTE,
            CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT,
            CommissionProcessus::CODE_TRANSFERT_GROSSISTE,
        ];
    }

    public static function processusLabel(string $code): string
    {
        return match ($code) {
            CommissionProcessus::CODE_VENTE => 'Ventes',
            CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT => 'Transferts logistiques',
            CommissionProcessus::CODE_TRANSFERT_GROSSISTE => 'Transferts grossistes',
            // Processus réel et courant (reporting), sans onglet de configuration dédié tant que
            // son barème reste hérité de CODE_LOGISTIQUE_TRANSFERT — cf. processusCodesDisponibles().
            CommissionProcessus::CODE_DISTRIBUTION_CLIENT => 'Distribution client',
            default => $code,
        };
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CommissionRegle::class);

        $orgId = auth()->user()->organization_id;

        $processusCode = $request->query('processus', CommissionProcessus::CODE_VENTE);
        if (! in_array($processusCode, self::processusCodesDisponibles(), true)) {
            $processusCode = CommissionProcessus::CODE_VENTE;
        }

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
            ->where('code', $processusCode)
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

        // Brouillon en cours (lot 2, ADR 0006) : l'écran reprend SA configuration — toute nouvelle
        // saisie s'y ajoute (cf. ReconfigurationPartagesService::enregistrerConfiguration()),
        // jamais une modification du barème en vigueur tant qu'il n'est pas publié.
        $brouillon = $processus ? CommissionBaremeBrouillon::enCoursPour($orgId, $processus->id) : null;
        $resumeBrouillon = null;
        if ($brouillon) {
            $lignes = $this->lignesDepuisBrouillon($brouillon->lignes, $categories, $typesVehicules);
            $groupes = ReconfigurationPartagesService::groupes($orgId, $processus, $brouillon->lignes, $brouillon);
            $resumeBrouillon = [
                'id' => $brouillon->id,
                'total' => $groupes->count(),
                'conformes' => $groupes->where('statut', ReconfigurationPartagesService::STATUT_CONFORME)->count(),
                'updated_at' => $brouillon->updated_at?->toIso8601String(),
            ];
        }

        return Inertia::render('settings/CommissionRegles/Index', [
            'processus_actif' => $processusCode,
            'brouillon' => $resumeBrouillon,
            'can_modifier' => auth()->user()->can('parametres.update'),
            'processus_options' => array_map(
                fn (string $code) => ['value' => $code, 'label' => self::processusLabel($code)],
                self::processusCodesDisponibles(),
            ),
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
     * Reconstitue, depuis la configuration d'un brouillon (format du payload
     * storeConfiguration()), les lignes affichées par l'écran — même forme que
     * configurationPourCategorie(), sans règle en base (regle_id vide).
     *
     * @param  array<int, array<string, mixed>>  $lignesBrouillon
     */
    private function lignesDepuisBrouillon(array $lignesBrouillon, Collection $categories, Collection $typesVehicules): array
    {
        $consultants = Prestataire::whereIn('id', collect($lignesBrouillon)->pluck('consultant_id')->filter())
            ->with(['personne', 'entrepriseTierce'])
            ->get()
            ->keyBy('id');
        $info = fn ($montant) => ['montant' => (float) $montant, 'effective_from' => '', 'regle_id' => ''];

        return collect($lignesBrouillon)
            ->map(function (array $ligne) use ($categories, $typesVehicules, $consultants, $info) {
                $consultant = $consultants->get($ligne['consultant_id'] ?? null);

                return [
                    'scope_type' => 'categorie',
                    'scope_id' => $ligne['categorie_id'],
                    'libelle' => $categories->firstWhere('id', $ligne['categorie_id'])?->nom ?? '—',
                    'beneficiaires' => $ligne['beneficiaires'],
                    'montants_standard' => collect($ligne['montants_standard'] ?? [])->map($info)->all(),
                    'consultant_id' => $ligne['consultant_id'] ?? null,
                    'consultant_label' => $consultant?->nom_complet ?? $consultant?->reference,
                    'exceptions' => collect($ligne['exceptions'] ?? [])->map(fn (array $e) => [
                        'type_vehicule_id' => $e['type_vehicule_id'],
                        'type_vehicule_label' => $typesVehicules->firstWhere('id', $e['type_vehicule_id'])?->nom ?? '—',
                        'montants' => collect($e['montants'] ?? [])->map($info)->all(),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
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
     * Enregistre toute la configuration visible après confirmation (bénéficiaires cochés,
     * consultant, barème général et exceptions véhicule). Une catégorie absente du payload voit
     * toutes ses règles closes (retrait complet) ; c'est aussi le mécanisme utilisé par le bouton
     * "Supprimer" du front, qui renvoie la configuration complète moins la catégorie retirée.
     *
     * Lot 2 (ADR 0006) : appliquée immédiatement seulement si aucun partage d'équipe n'en devient
     * non conforme ; sinon versée dans le brouillon du processus, publié plus tard avec les
     * partages reconfigurés (ReconfigurationPartagesService) — jamais une fenêtre où barème et
     * partages divergent.
     */
    public function storeConfiguration(StoreCommissionConfigurationRequest $request): RedirectResponse
    {
        $this->authorize('create', CommissionRegle::class);

        $data = $request->validated();
        $processusCode = $data['processus_code'];

        $resultat = ReconfigurationPartagesService::enregistrerConfiguration(
            auth()->user()->organization_id,
            $processusCode,
            $data['lignes'],
            auth()->id(),
        );

        if ($resultat['brouillon']) {
            return to_route('settings.commissions.brouillons.show', $resultat['brouillon'])
                ->with('success', sprintf(
                    "Nouveau barème préparé : %d partage(s) d'équipe à reconfigurer avant publication.",
                    $resultat['nb_groupes'],
                ));
        }

        return to_route('settings.commissions.index', ['processus' => $processusCode])
            ->with('success', 'Configuration des commissions enregistrée.');
    }

    /**
     * Aperçu, dans la fenêtre « Vérifier avant d'enregistrer », du nombre d'équipes dont le
     * partage devra être reconfiguré — aucune écriture.
     */
    public function apercuImpact(StoreCommissionConfigurationRequest $request): JsonResponse
    {
        $this->authorize('create', CommissionRegle::class);

        $data = $request->validated();

        return response()->json(ReconfigurationPartagesService::apercu(
            auth()->user()->organization_id,
            $data['processus_code'],
            $data['lignes'],
        ));
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

        $processus = CommissionProcessusDefaults::resoudreOuCreer($orgId, CommissionProcessus::CODE_VENTE);

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
            'libelle' => CommissionBaremeConfigurationService::libelleAuto($data['cible_type'], $scopeType, $scopeId),
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
}
