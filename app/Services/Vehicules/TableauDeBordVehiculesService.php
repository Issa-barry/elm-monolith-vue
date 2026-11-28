<?php

namespace App\Services\Vehicules;

use App\Enums\ClientType;
use App\Enums\ModeRemiseGrossiste;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutDepense;
use App\Enums\StatutTransfert;
use App\Features\ModuleFeature;
use App\Models\Categorie;
use App\Models\CommandeVenteRetour;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Models\VehiculeCapacite;
use App\Services\ModuleService;
use App\Services\SiteScopeService;
use App\Services\VehiculeCapaciteService;
use App\Support\Depenses\DepensePerimetre;
use App\Support\Periodes\Periode;
use App\Support\Periodes\TranchesPeriode;
use App\Support\Ventes\CommandeVenteFormBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord du parc, Stat-Véhicules V1 (docs/tableau-de-bord-vehicules.md) : strictement
 * consultatif, sans migration, il lit les véhicules, les ventes, les transferts et les dépenses sans
 * rien modifier.
 *
 * - **Parc** (`vehicules.read`) : état du jour des véhicules de l'agence **actuelle**
 *   (`vehicules.site_id`) dans les agences de l'utilisateur (SiteScopeService). Seul statut
 *   existant : actif ou inactif. Un véhicule actif est **opérationnel** s'il peut réaliser chacune
 *   des opérations de ses usages, selon les seules règles du serveur ; sinon il est **à configurer**.
 * - **Opérations et règles d'usage** (contrôles serveur existants, sans critère ajouté) :
 *   - vente standard : usage Vente, capacités obligatoires renseignées (ADR 0042) ;
 *   - distribution client : usage Logistique, équipe active avec un chauffeur actif
 *     (CommandeVenteFormBuilder::motifChauffeurIndisponible), capacités obligatoires ;
 *   - livraison grossiste : usage Grossiste (ADR 0023), capacités obligatoires ;
 *   - transfert : usage Logistique, équipe facultative.
 *   Le type d'une vente suit CommissionProcessusDefaults::identiteCodePourVente().
 * - **Activité** (période) : une **rotation** est un départ chargé du véhicule. Vente : date du
 *   chargement validé (`chargement_valide_at`), ou de validation pour une vente au comptoir partie
 *   dès sa création (`validated_at`), statuts partis seulement ; transfert : `date_depart_reelle`
 *   (au jour près), hors annulés. Agence **de l'opération** : celle de la vente, l'agence de départ
 *   du transfert. Ventes avec `ventes.read` et le module Ventes, transferts avec `logistique.read`
 *   et le module Logistique ; une source non autorisée n'est ni lue ni envoyée.
 * - **Jours d'activité** : couples (véhicule, jour) distincts, toutes opérations confondues : un
 *   véhicule n'est jamais compté deux fois le même jour.
 * - **Quantités** : par catégorie de produit, jamais additionnées entre catégories (sachets et
 *   bouteilles n'ont pas d'unité commune). Remplissage = chargé ÷ capacité actuelle du véhicule
 *   (vehicule_capacites, non historisée), sur les chargements contenant la catégorie ; un véhicule
 *   sans capacité pour la catégorie n'entre pas dans le calcul.
 * - **Dépenses** (`depenses.read`, module Dépenses, DepensePerimetre) : dépenses **validées**
 *   imputées à un véhicule, datées dans la période (`date_depense`), agence de la dépense.
 */
class TableauDeBordVehiculesService
{
    /** Périodes proposées : celles des autres tableaux de bord, dates libres comprises. */
    public const PERIODES = Periode::TABLEAU_DE_BORD;

    public const VENTE = 'vente';

    public const DISTRIBUTION = 'distribution';

    public const GROSSISTE = 'grossiste';

    public const TRANSFERT = 'transfert';

    /** Opérations dans l'ordre d'affichage, avec leur libellé et l'usage du véhicule qui les permet. */
    public const OPERATIONS = [
        self::VENTE => ['libelle' => 'Vente standard', 'usage' => 'Vente'],
        self::DISTRIBUTION => ['libelle' => 'Distribution client', 'usage' => 'Logistique'],
        self::GROSSISTE => ['libelle' => 'Livraison grossiste', 'usage' => 'Grossiste'],
        self::TRANSFERT => ['libelle' => 'Transfert', 'usage' => 'Logistique'],
    ];

    /** Une vente à ces statuts est partie chargée (rotation). */
    private const VENTES_PARTIES = [
        StatutCommandeVente::LIVRAISON_EN_COURS,
        StatutCommandeVente::LIVREE,
        StatutCommandeVente::FACTURATION,
        StatutCommandeVente::CLOTUREE,
        StatutCommandeVente::RETOURNEE,
    ];

    /** Vente en cours : chargement commencé, ou en livraison jusqu'au premier encaissement. */
    private const VENTES_EN_COURS = [StatutCommandeVente::CHARGEMENT_EN_COURS, StatutCommandeVente::LIVRAISON_EN_COURS];

    private const TRANSFERTS_EN_COURS = [StatutTransfert::CHARGEMENT, StatutTransfert::TRANSIT];

    /**
     * Index écartés sous MySQL (docs/tableau-de-bord-vehicules.md, Performances). La date de départ
     * n'a pas d'index (V1 sans migration) : laissé libre, MySQL lit toutes les ventes ou tous les
     * transferts de l'organisation dans l'ordre de l'index unique (organization_id, reference), par
     * accès aléatoires, environ six fois plus lentement qu'un parcours de la table.
     */
    private const INDEX_ECARTES_VENTES = 'commandes_ventes_organization_id_reference_unique, commandes_ventes_organization_id_created_at_index, commandes_ventes_organization_id_statut_index';

    private const INDEX_ECARTES_TRANSFERTS = 'transferts_logistiques_organization_id_reference_unique, transferts_logistiques_organization_id_created_at_index, transferts_logistiques_organization_id_statut_index';

    /** Écart de réception d'une ligne de transfert (TransfertLigne::ecart, ADR 0032). */
    private const ECART = 'tl.quantite_recue + COALESCE(tl.quantite_retournee, 0) - tl.quantite_chargee';

    public function __construct(
        private readonly SiteScopeService $siteScope,
        private readonly VehiculeCapaciteService $capacites,
        private readonly CommandeVenteFormBuilder $formulaireVente,
        private readonly DepensePerimetre $perimetreDepenses,
    ) {}

    /**
     * Agences que l'utilisateur peut choisir : toutes celles de l'organisation avec la vision de
     * toutes les agences, ses agences de rattachement sinon (SiteScopeService).
     *
     * @return list<array{value: string, label: string}>
     */
    public function agencesProposees(User $user): array
    {
        $sites = $user->voitToutesLesAgences()
            ? Site::where('organization_id', $user->organization_id)->orderBy('nom')->get(['id', 'nom'])
            : $user->sites()->where('sites.organization_id', $user->organization_id)->orderBy('sites.nom')->get(['sites.id', 'sites.nom']);

        return $sites->map(fn (Site $s) => ['value' => (string) $s->id, 'label' => $s->nom])->values()->all();
    }

    /**
     * @param  list<array{value: string, label: string}>  $agences  agences proposées (agencesProposees())
     * @param  ?string  $agence  agence choisie, déjà vérifiée ; null = tout le périmètre
     * @return array<string, mixed>
     */
    public function calculer(User $user, Periode $periode, array $agences, ?string $agence, ?CarbonImmutable $maintenant = null): array
    {
        $maintenant ??= CarbonImmutable::now();
        $orgId = (string) $user->organization_id;
        $organisation = $user->organization;

        $voitVentes = $user->can('ventes.read') && ModuleService::isActive(ModuleFeature::VENTES, $organisation);
        $voitTransferts = $user->can('logistique.read') && ModuleService::isActive(ModuleFeature::LOGISTIQUE, $organisation);
        $voitDepenses = $user->can('depenses.read') && ModuleService::isActive(ModuleFeature::DEPENSES, $organisation);
        $voitActivite = $voitVentes || $voitTransferts;

        // Agences de l'opération : l'agence choisie, sinon tout le périmètre (null = aucune limite).
        $sitesOperation = $agence !== null ? [$agence] : ($user->voitToutesLesAgences() ? null : array_column($agences, 'value'));

        $parc = $this->parc($user, $agence);
        $etats = $parc->mapWithKeys(fn (Vehicule $v) => [(string) $v->id => $this->etat($v)]);

        $rotationsVentes = $voitVentes ? $this->rotationsVentes($orgId, $periode, $sitesOperation) : collect();
        $rotationsTransferts = $voitTransferts ? $this->rotationsTransferts($orgId, $periode, $sitesOperation) : collect();
        $rotations = $rotationsVentes->concat($rotationsTransferts);

        $chargements = collect();
        if ($voitVentes) {
            $chargements = $chargements->concat($this->chargementsVentes($orgId, $periode, $sitesOperation));
        }
        if ($voitTransferts) {
            $chargements = $chargements->concat($this->chargementsTransferts($orgId, $periode, $sitesOperation));
        }

        $depenses = $voitDepenses ? $this->depensesParVehicule($user, $periode, $sitesOperation) : null;
        $totalRotations = (int) $rotations->sum('rotations');

        return [
            'periode' => [...$periode->pourFront(self::PERIODES), 'libelle' => $periode->libelle()],
            'agences' => $agences,
            'agence' => $agence,
            'perimetre_limite' => ! $user->voitToutesLesAgences(),
            'blocs' => ['ventes' => $voitVentes, 'transferts' => $voitTransferts, 'depenses' => $voitDepenses],
            'parc' => $this->syntheseParc($user, $parc, $etats, $voitVentes, $voitTransferts),
            'operations' => $this->parOperation($parc, $etats, $rotations, $voitVentes, $voitTransferts),
            'activite' => $voitActivite ? $this->activite($parc, $rotations, $voitTransferts) : null,
            'evolution' => $voitActivite ? $this->evolution($rotations, $periode, $maintenant) : null,
            'categories' => $this->parCategorie($orgId, $parc, $chargements, $voitActivite, $voitTransferts),
            'depenses' => $depenses === null ? null : $this->syntheseDepenses($depenses, $voitActivite ? $totalRotations : null),
            'vehicules' => $this->classement($orgId, $parc, $etats, $rotations, $depenses, $voitActivite),
            'a_configurer' => $this->aConfigurer($parc, $etats),
        ];
    }

    /**
     * Véhicules du parc : agence actuelle dans le périmètre de l'utilisateur, et l'agence choisie.
     *
     * @return Collection<int, Vehicule>
     */
    private function parc(User $user, ?string $agence): Collection
    {
        $query = Vehicule::query()
            ->where('organization_id', $user->organization_id)
            ->with([
                'site:id,nom',
                'typeVehicule:id,nom',
                'capacites',
                // Même chargement que le formulaire de vente : chauffeurs seulement, pour le motif.
                'equipe.livreurs' => fn ($q) => $q->wherePivot('role', 'chauffeur')->with('personne'),
            ])
            ->orderBy('nom_vehicule');
        $this->siteScope->applyToQuery($query, $user);

        if ($agence !== null) {
            $query->where('site_id', $agence);
        }

        return $query->get();
    }

    /**
     * État du jour d'un véhicule : inactif, ou actif avec, pour chaque opération de ses usages, la
     * possibilité de la réaliser et les motifs de blocage, tous tirés des contrôles serveur.
     *
     * @return array{statut: string, operations: array<string, bool>, motifs: list<string>}
     */
    private function etat(Vehicule $v): array
    {
        $operations = array_fill_keys(array_keys(self::OPERATIONS), false);

        if (! $v->is_active) {
            return ['statut' => 'inactif', 'operations' => $operations, 'motifs' => []];
        }

        if (! $v->aAuMoinsUnUsage()) {
            return ['statut' => 'a_configurer', 'operations' => $operations, 'motifs' => ['Aucun usage coché : le véhicule n’est proposé pour aucune opération.']];
        }

        $manquantes = array_column($this->capacites->capacitesObligatoiresManquantes($v), 'categorie_nom');
        $chauffeur = $v->livraison_logistique ? $this->formulaireVente->motifChauffeurIndisponible($v) : null;
        $operations = [
            self::VENTE => $v->livraison_vente && $manquantes === [],
            self::DISTRIBUTION => $v->livraison_logistique && $chauffeur === null && $manquantes === [],
            self::GROSSISTE => $v->livraison_grossiste && $manquantes === [],
            self::TRANSFERT => (bool) $v->livraison_logistique,
        ];

        $motifs = [];
        if ($manquantes !== []) {
            $motifs[] = 'Capacité maximale à renseigner pour '.implode(', ', $manquantes).' : la vente de ces produits est refusée.';
        }
        if ($chauffeur !== null) {
            $motifs[] = 'Distribution impossible : '.$chauffeur;
        }

        return ['statut' => $motifs === [] ? 'operationnel' : 'a_configurer', 'operations' => $operations, 'motifs' => $motifs];
    }

    /**
     * @param  Collection<int, Vehicule>  $parc
     * @param  Collection<string, array{statut: string, operations: array<string, bool>, motifs: list<string>}>  $etats
     * @return array<string, mixed>
     */
    private function syntheseParc(User $user, Collection $parc, Collection $etats, bool $voitVentes, bool $voitTransferts): array
    {
        $actifs = $parc->filter(fn (Vehicule $v) => $v->is_active)->pluck('id')->map(fn ($id) => (string) $id)->values()->all();

        return [
            'total' => $parc->count(),
            'actifs' => count($actifs),
            'inactifs' => $parc->count() - count($actifs),
            'operationnels' => $etats->where('statut', 'operationnel')->count(),
            'a_configurer' => $etats->where('statut', 'a_configurer')->count(),
            'en_mission' => $voitVentes || $voitTransferts ? $this->enMission($user, $actifs, $voitVentes, $voitTransferts) : null,
        ];
    }

    /**
     * Véhicules actifs du parc qui ont une opération en cours, visible dans le périmètre du module
     * concerné : vente en chargement ou en livraison (jusqu'au premier encaissement), transfert en
     * chargement ou en transit.
     *
     * @param  list<string>  $actifs
     * @return array{vehicules: int, ventes: ?int, transferts: ?int}
     */
    private function enMission(User $user, array $actifs, bool $voitVentes, bool $voitTransferts): array
    {
        $ventes = collect();
        $transferts = collect();

        if ($actifs !== [] && $voitVentes) {
            $query = DB::table('commandes_ventes')
                ->where('organization_id', $user->organization_id)
                ->whereNull('deleted_at')
                ->whereIn('statut', array_map(fn ($s) => $s->value, self::VENTES_EN_COURS))
                ->whereIn('vehicule_id', $actifs);
            // Périmètre de la liste des ventes : agence de la vente (null = toutes les agences).
            self::limiterAgences($query, 'site_id', $user->voitToutesLesAgences()
                ? null
                : $this->siteScope->accessibleSiteIds($user)->map(fn ($id) => (string) $id)->values()->all());
            $ventes = $query->distinct()->pluck('vehicule_id')->map(fn ($id) => (string) $id);
        }

        if ($actifs !== [] && $voitTransferts) {
            $transferts = TransfertLogistique::query()
                ->where('organization_id', $user->organization_id)
                ->whereIn('statut', array_map(fn ($s) => $s->value, self::TRANSFERTS_EN_COURS))
                ->whereIn('vehicule_id', $actifs)
                ->dansAgencesDe($user)
                ->distinct()
                ->pluck('vehicule_id')
                ->map(fn ($id) => (string) $id);
        }

        return [
            'vehicules' => $ventes->concat($transferts)->unique()->count(),
            'ventes' => $voitVentes ? $ventes->count() : null,
            'transferts' => $voitTransferts ? $transferts->count() : null,
        ];
    }

    /**
     * Rotations des ventes par véhicule, type d'opération et jour de départ.
     *
     * @param  ?list<string>  $sites  agences de l'opération (null = aucune limite)
     * @return Collection<int, array{vehicule_id: string, operation: string, jour: string, rotations: int, avec_retour: int, avec_ecart: int}>
     */
    private function rotationsVentes(string $orgId, Periode $periode, ?array $sites): Collection
    {
        $operation = self::operationVenteSql();
        // Tout retour de livraison, partiel ou total, est enregistré dans commande_vente_retours
        // (CommandeVenteRetourService), table bien plus petite que les lignes de vente. Une
        // régularisation (ADR 0043) y figure aussi, mais corrige une saisie : ce n'est pas un retour.
        $retour = "(cv.statut = '".StatutCommandeVente::RETOURNEE->value."' OR EXISTS (SELECT 1 FROM commande_vente_retours r WHERE r.commande_vente_id = cv.id AND r.nature = '".CommandeVenteRetour::NATURE_RETOUR_LIVRAISON."'))";

        return $this->ventesParties($orgId, $periode, $sites)
            ->selectRaw('cv.vehicule_id')
            ->selectRaw("{$operation} as operation")
            ->selectRaw('DATE(COALESCE(cv.chargement_valide_at, cv.validated_at)) as jour')
            ->selectRaw('COUNT(*) as rotations')
            ->selectRaw("SUM(CASE WHEN {$retour} THEN 1 ELSE 0 END) as avec_retour")
            ->groupBy('cv.vehicule_id', 'operation', 'jour')
            ->get()
            ->map(fn ($l) => [
                'vehicule_id' => (string) $l->vehicule_id,
                'operation' => (string) $l->operation,
                'jour' => substr((string) $l->jour, 0, 10),
                'rotations' => (int) $l->rotations,
                'avec_retour' => (int) $l->avec_retour,
                'avec_ecart' => 0,
            ]);
    }

    /**
     * Ventes parties avec un véhicule de la flotte, dans la période et les agences de l'opération.
     * Date de départ : le chargement validé, ou la validation d'une vente au comptoir (PDV), partie en
     * livraison dès sa création sans étape de chargement.
     *
     * @param  ?list<string>  $sites
     */
    private function ventesParties(string $orgId, Periode $periode, ?array $sites): Builder
    {
        $query = self::ecarterIndex(DB::table('commandes_ventes as cv'), self::INDEX_ECARTES_VENTES)
            ->leftJoin('clients as c', fn ($j) => $j->on('c.id', '=', 'cv.client_id')->whereNull('c.deleted_at'))
            ->where('cv.organization_id', $orgId)
            ->whereNull('cv.deleted_at')
            ->whereNotNull('cv.vehicule_id')
            ->whereIn('cv.statut', array_map(fn ($s) => $s->value, self::VENTES_PARTIES))
            ->where(fn ($q) => $q
                ->where(fn ($a) => $periode->appliquer($a->whereNotNull('cv.chargement_valide_at'), 'cv.chargement_valide_at'))
                ->orWhere(fn ($b) => $periode->appliquer($b->whereNull('cv.chargement_valide_at')->whereNotNull('cv.validated_at'), 'cv.validated_at')));

        return self::limiterAgences($query, 'cv.site_id', $sites);
    }

    /**
     * Type d'opération d'une vente, même règle que CommissionProcessusDefaults::identiteCodePourVente() :
     * distribution client, sinon livraison grossiste (client grossiste, nature figée sur la vente ou
     * à défaut celle de la fiche client, en mode livraison), sinon vente standard.
     */
    private static function operationVenteSql(): string
    {
        return "CASE
            WHEN cv.nature_operation = '".NatureOperation::DISTRIBUTION_CLIENT->value."' THEN '".self::DISTRIBUTION."'
            WHEN COALESCE(cv.nature_client, c.type) = '".ClientType::GROSSISTE->value."'
                AND cv.mode_remise_grossiste = '".ModeRemiseGrossiste::LIVRAISON->value."' THEN '".self::GROSSISTE."'
            ELSE '".self::VENTE."' END";
    }

    /**
     * Rotations des transferts par véhicule et jour de départ réel, agence de départ.
     *
     * @param  ?list<string>  $sites
     * @return Collection<int, array{vehicule_id: string, operation: string, jour: string, rotations: int, avec_retour: int, avec_ecart: int}>
     */
    private function rotationsTransferts(string $orgId, Periode $periode, ?array $sites): Collection
    {
        $ecart = self::ECART;
        // Tout retour au site source est enregistré dans transfert_retours (TransfertRetourService).
        $retour = "(t.statut = '".StatutTransfert::RETOURNE->value."' OR EXISTS (SELECT 1 FROM transfert_retours r WHERE r.transfert_logistique_id = t.id))";
        $avecEcart = "EXISTS (SELECT 1 FROM transfert_lignes tl WHERE tl.transfert_logistique_id = t.id AND tl.quantite_recue IS NOT NULL AND tl.quantite_chargee IS NOT NULL AND {$ecart} <> 0)";

        return $this->transfertsPartis($orgId, $periode, $sites)
            ->select('t.vehicule_id', 't.date_depart_reelle as jour')
            ->selectRaw('COUNT(*) as rotations')
            ->selectRaw("SUM(CASE WHEN {$retour} THEN 1 ELSE 0 END) as avec_retour")
            ->selectRaw("SUM(CASE WHEN {$avecEcart} THEN 1 ELSE 0 END) as avec_ecart")
            ->groupBy('t.vehicule_id', 't.date_depart_reelle')
            ->get()
            ->map(fn ($l) => [
                'vehicule_id' => (string) $l->vehicule_id,
                'operation' => self::TRANSFERT,
                'jour' => substr((string) $l->jour, 0, 10),
                'rotations' => (int) $l->rotations,
                'avec_retour' => (int) $l->avec_retour,
                'avec_ecart' => (int) $l->avec_ecart,
            ]);
    }

    /**
     * Transferts partis (départ réel daté, hors annulés) avec un véhicule, dans la période.
     *
     * @param  ?list<string>  $sites
     */
    private function transfertsPartis(string $orgId, Periode $periode, ?array $sites): Builder
    {
        $query = self::ecarterIndex(DB::table('transferts_logistiques as t'), self::INDEX_ECARTES_TRANSFERTS)
            ->where('t.organization_id', $orgId)
            ->whereNull('t.deleted_at')
            ->whereNotNull('t.vehicule_id')
            ->whereNotNull('t.date_depart_reelle')
            ->where('t.statut', '!=', StatutTransfert::ANNULE->value);
        $periode->appliquer($query, 't.date_depart_reelle', true);

        return self::limiterAgences($query, 't.site_source_id', $sites);
    }

    /**
     * Quantités des ventes parties par véhicule et catégorie de produit.
     *
     * @param  ?list<string>  $sites
     * @return Collection<int, array{vehicule_id: string, categorie_id: ?string, charge: int, retourne: int, chargements: int, manquants: int, surplus: int}>
     */
    private function chargementsVentes(string $orgId, Periode $periode, ?array $sites): Collection
    {
        // Quantité retirée par une régularisation (ADR 0043) : erreur de saisie, jamais chargée ni
        // retournée ; elle est déduite du chargé et du retourné (`quantite_retournee` l'inclut).
        $regularise = DB::table('commande_vente_retour_lignes as rl')
            ->join('commande_vente_retours as r', 'r.id', '=', 'rl.commande_vente_retour_id')
            ->where('r.organization_id', $orgId)
            ->where('r.nature', CommandeVenteRetour::NATURE_REGULARISATION)
            ->groupBy('rl.commande_vente_ligne_id')
            ->select('rl.commande_vente_ligne_id')
            ->selectRaw('SUM(rl.quantite_retournee) as quantite');
        $charge = 'COALESCE(l.quantite_chargee, l.quantite_demandee, 0) - COALESCE(reg.quantite, 0)';
        $retourne = 'COALESCE(l.quantite_retournee, 0) - COALESCE(reg.quantite, 0)';

        return $this->ventesParties($orgId, $periode, $sites)
            ->join('commande_vente_lignes as l', 'l.commande_vente_id', '=', 'cv.id')
            ->leftJoinSub($regularise, 'reg', 'reg.commande_vente_ligne_id', '=', 'l.id')
            ->leftJoin('produit_variantes as pv', 'pv.id', '=', 'l.variante_id')
            ->leftJoin('produits as p', 'p.id', '=', 'pv.produit_id')
            ->whereRaw("{$charge} > 0")
            ->select('cv.vehicule_id', 'p.categorie_id')
            ->selectRaw("SUM({$charge}) as charge")
            ->selectRaw("SUM({$retourne}) as retourne")
            ->selectRaw('COUNT(DISTINCT cv.id) as chargements')
            ->groupBy('cv.vehicule_id', 'p.categorie_id')
            ->get()
            ->map(fn ($l) => [
                'vehicule_id' => (string) $l->vehicule_id,
                'categorie_id' => $l->categorie_id === null ? null : (string) $l->categorie_id,
                'charge' => (int) $l->charge,
                'retourne' => (int) $l->retourne,
                'chargements' => (int) $l->chargements,
                'manquants' => 0,
                'surplus' => 0,
            ]);
    }

    /**
     * Quantités des transferts partis par véhicule et catégorie, avec les écarts de réception
     * (reçu + retourné − chargé, même règle que Stat-Stock) des lignes réceptionnées.
     *
     * @param  ?list<string>  $sites
     * @return Collection<int, array{vehicule_id: string, categorie_id: ?string, charge: int, retourne: int, chargements: int, manquants: int, surplus: int}>
     */
    private function chargementsTransferts(string $orgId, Periode $periode, ?array $sites): Collection
    {
        $ecart = self::ECART;
        $recue = 'tl.quantite_recue IS NOT NULL';

        return $this->transfertsPartis($orgId, $periode, $sites)
            ->join('transfert_lignes as tl', 'tl.transfert_logistique_id', '=', 't.id')
            ->leftJoin('produit_variantes as pv', 'pv.id', '=', 'tl.variante_id')
            ->leftJoin('produits as p', 'p.id', '=', 'pv.produit_id')
            ->where('tl.quantite_chargee', '>', 0)
            ->select('t.vehicule_id', 'p.categorie_id')
            ->selectRaw('SUM(tl.quantite_chargee) as charge')
            ->selectRaw('SUM(COALESCE(tl.quantite_retournee, 0)) as retourne')
            ->selectRaw('COUNT(DISTINCT t.id) as chargements')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$recue} AND {$ecart} < 0 THEN -({$ecart}) ELSE 0 END), 0) as manquants")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$recue} AND {$ecart} > 0 THEN {$ecart} ELSE 0 END), 0) as surplus")
            ->groupBy('t.vehicule_id', 'p.categorie_id')
            ->get()
            ->map(fn ($l) => [
                'vehicule_id' => (string) $l->vehicule_id,
                'categorie_id' => $l->categorie_id === null ? null : (string) $l->categorie_id,
                'charge' => (int) $l->charge,
                'retourne' => (int) $l->retourne,
                'chargements' => (int) $l->chargements,
                'manquants' => (int) $l->manquants,
                'surplus' => (int) $l->surplus,
            ]);
    }

    /**
     * Dépenses validées imputées à un véhicule, par véhicule : périmètre Dépenses (DepensePerimetre),
     * agence de la dépense limitée aux agences du tableau de bord.
     *
     * @param  ?list<string>  $sites
     * @return Collection<string, array{montant: float, nombre: int}>
     */
    private function depensesParVehicule(User $user, Periode $periode, ?array $sites): Collection
    {
        $query = DB::table('depenses as d')
            ->where('d.organization_id', $user->organization_id)
            ->whereNull('d.deleted_at')
            ->where('d.statut', StatutDepense::VALIDE->value)
            ->where('d.beneficiaire_type', 'vehicule')
            ->whereNotNull('d.beneficiaire_id');
        $periode->appliquer($query, 'd.date_depense', true);
        self::limiterAgences($query, 'd.site_id', $sites);
        $this->perimetreDepenses->appliquer($query, $user, 'd.site_id', 'd.user_id');

        return $query
            ->groupBy('d.beneficiaire_id')
            ->select('d.beneficiaire_id')
            ->selectRaw('SUM(d.montant) as montant')
            ->selectRaw('COUNT(*) as nombre')
            ->get()
            ->mapWithKeys(fn ($l) => [(string) $l->beneficiaire_id => ['montant' => (float) $l->montant, 'nombre' => (int) $l->nombre]]);
    }

    /**
     * Par type d'opération : véhicules actifs du parc qui ont l'usage, ceux qui peuvent la réaliser
     * aujourd'hui, et l'activité de la période quand sa source est autorisée.
     *
     * @param  Collection<int, Vehicule>  $parc
     * @param  Collection<string, array{statut: string, operations: array<string, bool>, motifs: list<string>}>  $etats
     * @param  Collection<int, array<string, mixed>>  $rotations
     * @return list<array<string, mixed>>
     */
    private function parOperation(Collection $parc, Collection $etats, Collection $rotations, bool $voitVentes, bool $voitTransferts): array
    {
        $actifs = $parc->filter(fn (Vehicule $v) => $v->is_active);
        $usage = [
            self::VENTE => fn (Vehicule $v) => (bool) $v->livraison_vente,
            self::DISTRIBUTION => fn (Vehicule $v) => (bool) $v->livraison_logistique,
            self::GROSSISTE => fn (Vehicule $v) => (bool) $v->livraison_grossiste,
            self::TRANSFERT => fn (Vehicule $v) => (bool) $v->livraison_logistique,
        ];

        return collect(self::OPERATIONS)->map(function (array $operation, string $code) use ($actifs, $etats, $rotations, $usage, $voitVentes, $voitTransferts) {
            $avecUsage = $actifs->filter($usage[$code]);
            $possibles = $avecUsage->filter(fn (Vehicule $v) => $etats[(string) $v->id]['operations'][$code])->count();
            $visible = $code === self::TRANSFERT ? $voitTransferts : $voitVentes;
            $lignes = $rotations->where('operation', $code);

            return [
                'code' => $code,
                'libelle' => $operation['libelle'],
                'usage' => $operation['usage'],
                'vehicules' => $avecUsage->count(),
                'possibles' => $possibles,
                'bloques' => $avecUsage->count() - $possibles,
                'rotations' => $visible ? (int) $lignes->sum('rotations') : null,
                'vehicules_utilises' => $visible ? $lignes->pluck('vehicule_id')->unique()->count() : null,
                'jours_vehicule' => $visible ? $lignes->map(fn ($l) => $l['vehicule_id'].'|'.$l['jour'])->unique()->count() : null,
            ];
        })->values()->all();
    }

    /**
     * Activité de la période, toutes sources autorisées confondues.
     *
     * @param  Collection<int, Vehicule>  $parc
     * @param  Collection<int, array<string, mixed>>  $rotations
     * @return array<string, mixed>
     */
    private function activite(Collection $parc, Collection $rotations, bool $voitTransferts): array
    {
        $actifs = $parc->filter(fn (Vehicule $v) => $v->is_active)->pluck('id')->map(fn ($id) => (string) $id)->flip();
        $utilises = $rotations->pluck('vehicule_id')->unique();
        $actifsUtilises = $utilises->filter(fn ($id) => $actifs->has($id))->count();
        $total = (int) $rotations->sum('rotations');

        return [
            'rotations' => $total,
            'vehicules_utilises' => $utilises->count(),
            'actifs' => $actifs->count(),
            'actifs_utilises' => $actifsUtilises,
            'taux_utilisation' => $actifs->count() === 0 ? null : round($actifsUtilises / $actifs->count() * 100, 4),
            'jours_vehicule' => $rotations->map(fn ($l) => $l['vehicule_id'].'|'.$l['jour'])->unique()->count(),
            'avec_retour' => (int) $rotations->sum('avec_retour'),
            'taux_retour' => $total === 0 ? null : round($rotations->sum('avec_retour') / $total * 100, 4),
            'transferts_avec_ecart' => $voitTransferts ? (int) $rotations->sum('avec_ecart') : null,
        ];
    }

    /**
     * Rotations par jour ou par mois (TranchesPeriode), une série par type d'opération autorisé.
     *
     * @param  Collection<int, array<string, mixed>>  $rotations
     * @return array{granularite: string, tranches: list<array{cle: string, label: string}>, series: list<array{code: string, label: string, valeurs: list<int>}>}
     */
    private function evolution(Collection $rotations, Periode $periode, CarbonImmutable $maintenant): array
    {
        $decoupage = TranchesPeriode::pour($periode);
        $parOperation = [];
        $clesAvecDonnees = [];
        foreach ($rotations as $l) {
            $tranche = $decoupage->cle($l['jour']);
            $parOperation[$l['operation']][$tranche] = ($parOperation[$l['operation']][$tranche] ?? 0) + $l['rotations'];
            $clesAvecDonnees[$tranche] = true;
        }

        $tranches = array_map(
            fn (array $t) => ['cle' => (string) $t['cle'], 'label' => (string) $t['label']],
            $decoupage->tranches($periode, array_map('strval', array_keys($clesAvecDonnees)), $maintenant),
        );

        $series = [];
        foreach (self::OPERATIONS as $code => $operation) {
            if (! isset($parOperation[$code])) {
                continue;
            }
            $series[] = [
                'code' => $code,
                'label' => $operation['libelle'],
                'valeurs' => array_map(fn (array $t) => (int) ($parOperation[$code][$t['cle']] ?? 0), $tranches),
            ];
        }

        return ['granularite' => $decoupage->granularite(), 'tranches' => $tranches, 'series' => $series];
    }

    /**
     * Par catégorie de produit, jamais additionnée à une autre : capacité du parc actif, quantités
     * chargées et retournées, remplissage, écarts de réception.
     *
     * @param  Collection<int, Vehicule>  $parc
     * @param  Collection<int, array<string, mixed>>  $chargements
     * @return list<array<string, mixed>>
     */
    private function parCategorie(string $orgId, Collection $parc, Collection $chargements, bool $voitActivite, bool $voitTransferts): array
    {
        $capaciteParc = [];
        $plafonnes = [];
        foreach ($parc->filter(fn (Vehicule $v) => $v->is_active) as $v) {
            foreach ($v->capacites as $c) {
                $cle = (string) $c->categorie_id;
                $capaciteParc[$cle] = ($capaciteParc[$cle] ?? 0) + (int) $c->capacite_max;
                $plafonnes[$cle] = ($plafonnes[$cle] ?? 0) + 1;
            }
        }

        // Capacité actuelle de chaque véhicule qui a chargé, parc ou non (non historisée).
        $capaciteVehicule = $chargements->isEmpty() ? collect() : VehiculeCapacite::query()
            ->whereIn('vehicule_id', $chargements->pluck('vehicule_id')->unique()->values()->all())
            ->get(['vehicule_id', 'categorie_id', 'capacite_max'])
            ->mapWithKeys(fn (VehiculeCapacite $c) => [$c->vehicule_id.'|'.$c->categorie_id => (int) $c->capacite_max]);

        $quantites = [];
        foreach ($chargements as $l) {
            $cle = $l['categorie_id'] ?? '';
            $q = $quantites[$cle] ?? ['charge' => 0, 'retourne' => 0, 'chargements' => 0, 'charge_plafonne' => 0, 'capacite_offerte' => 0, 'manquants' => 0, 'surplus' => 0];
            $q['charge'] += $l['charge'];
            $q['retourne'] += $l['retourne'];
            $q['chargements'] += $l['chargements'];
            $q['manquants'] += $l['manquants'];
            $q['surplus'] += $l['surplus'];
            $capacite = $l['categorie_id'] === null ? null : $capaciteVehicule->get($l['vehicule_id'].'|'.$l['categorie_id']);
            if ($capacite !== null && $capacite > 0) {
                $q['charge_plafonne'] += $l['charge'];
                $q['capacite_offerte'] += $l['chargements'] * $capacite;
            }
            $quantites[$cle] = $q;
        }

        $ids = array_values(array_filter(array_unique([...array_keys($capaciteParc), ...array_keys($quantites)]), fn ($id) => $id !== ''));
        $noms = $ids === [] ? collect() : Categorie::withTrashed()->where('organization_id', $orgId)->whereIn('id', $ids)->pluck('nom', 'id');

        $lignes = [];
        foreach ([...$ids, ...(isset($quantites['']) ? [''] : [])] as $id) {
            $q = $quantites[$id] ?? null;
            $lignes[] = [
                'categorie_id' => $id === '' ? null : $id,
                'categorie' => $id === '' ? 'Sans catégorie' : ($noms[$id] ?? '—'),
                'capacite_parc' => $capaciteParc[$id] ?? 0,
                'vehicules_plafonnes' => $plafonnes[$id] ?? 0,
                'charge' => $voitActivite ? ($q['charge'] ?? 0) : null,
                'chargements' => $voitActivite ? ($q['chargements'] ?? 0) : null,
                'remplissage' => $voitActivite && ($q['capacite_offerte'] ?? 0) > 0 ? round($q['charge_plafonne'] / $q['capacite_offerte'] * 100, 4) : null,
                'retourne' => $voitActivite ? ($q['retourne'] ?? 0) : null,
                'taux_retour' => $voitActivite && ($q['charge'] ?? 0) > 0 ? round($q['retourne'] / $q['charge'] * 100, 4) : null,
                'ecarts_manquants' => $voitTransferts ? ($q['manquants'] ?? 0) : null,
                'ecarts_surplus' => $voitTransferts ? ($q['surplus'] ?? 0) : null,
            ];
        }

        usort($lignes, fn (array $a, array $b) => [$a['categorie_id'] === null, $a['categorie']] <=> [$b['categorie_id'] === null, $b['categorie']]);

        return $lignes;
    }

    /**
     * @param  Collection<string, array{montant: float, nombre: int}>  $depenses
     * @return array<string, mixed>
     */
    private function syntheseDepenses(Collection $depenses, ?int $rotations): array
    {
        $montant = (float) $depenses->sum('montant');

        return [
            'montant' => $montant,
            'nombre' => (int) $depenses->sum('nombre'),
            'vehicules' => $depenses->count(),
            'rotations' => $rotations,
            'cout_par_rotation' => $rotations === null || $rotations === 0 ? null : round($montant / $rotations, 2),
        ];
    }

    /**
     * Un véhicule par ligne : ceux du parc et ceux qui ont une activité ou une dépense comptée,
     * même rattachés aujourd'hui à une autre agence. Tri par défaut : les plus actifs d'abord.
     *
     * @param  Collection<int, Vehicule>  $parc
     * @param  Collection<string, array{statut: string, operations: array<string, bool>, motifs: list<string>}>  $etats
     * @param  Collection<int, array<string, mixed>>  $rotations
     * @param  ?Collection<string, array{montant: float, nombre: int}>  $depenses
     * @return list<array<string, mixed>>
     */
    private function classement(string $orgId, Collection $parc, Collection $etats, Collection $rotations, ?Collection $depenses, bool $voitActivite): array
    {
        $parVehicule = $rotations->groupBy('vehicule_id');
        $horsParc = $parVehicule->keys()
            ->merge($depenses?->keys() ?? [])
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->diff($parc->pluck('id')->map(fn ($id) => (string) $id))
            ->values();

        $autres = $horsParc->isEmpty() ? collect() : Vehicule::withTrashed()
            ->where('organization_id', $orgId)
            ->whereIn('id', $horsParc->all())
            ->with([
                'site:id,nom',
                'typeVehicule:id,nom',
                'capacites',
                'equipe.livreurs' => fn ($q) => $q->wherePivot('role', 'chauffeur')->with('personne'),
            ])
            ->get();

        $lignes = $parc->concat($autres)->map(function (Vehicule $v) use ($etats, $parVehicule, $depenses, $voitActivite) {
            $id = (string) $v->id;
            $supprime = $v->trashed();
            $etat = $supprime ? null : ($etats[$id] ?? $this->etat($v));
            $lignesVehicule = $parVehicule->get($id, collect());

            return [
                'id' => $id,
                'nom' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
                'site' => $v->site?->nom ?? '—',
                'type' => $v->typeVehicule?->nom ?? '—',
                'usages' => $v->usage_label,
                'statut' => $etat['statut'] ?? 'inactif',
                'statut_label' => match ($etat['statut'] ?? null) {
                    'operationnel' => 'Opérationnel',
                    'a_configurer' => 'À configurer',
                    null => 'Supprimé',
                    default => 'Inactif',
                },
                'supprime' => $supprime,
                'rotations' => $voitActivite ? (int) $lignesVehicule->sum('rotations') : null,
                'jours' => $voitActivite ? $lignesVehicule->pluck('jour')->unique()->count() : null,
                'avec_retour' => $voitActivite ? (int) $lignesVehicule->sum('avec_retour') : null,
                'depenses' => $depenses === null ? null : (float) ($depenses[$id]['montant'] ?? 0),
            ];
        });

        return $lignes
            ->sortBy([['rotations', 'desc'], ['depenses', 'desc'], ['nom', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Véhicules actifs du parc qui ne peuvent pas réaliser toutes les opérations de leurs usages,
     * avec les motifs du serveur.
     *
     * @param  Collection<int, Vehicule>  $parc
     * @param  Collection<string, array{statut: string, operations: array<string, bool>, motifs: list<string>}>  $etats
     * @return list<array{id: string, nom: string, immatriculation: ?string, site: string, usages: string, motifs: list<string>}>
     */
    private function aConfigurer(Collection $parc, Collection $etats): array
    {
        return $parc
            ->filter(fn (Vehicule $v) => $etats[(string) $v->id]['statut'] === 'a_configurer')
            ->map(fn (Vehicule $v) => [
                'id' => (string) $v->id,
                'nom' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
                'site' => $v->site?->nom ?? '—',
                'usages' => $v->usage_label,
                'motifs' => $etats[(string) $v->id]['motifs'],
            ])
            ->values()
            ->all();
    }

    /** Index écartés sous MySQL et MariaDB seulement ; SQLite (tests) garde son plan. */
    private static function ecarterIndex(Builder $query, string $index): Builder
    {
        return in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? $query->ignoreIndex($index)
            : $query;
    }

    /**
     * @param  ?list<string>  $sites  null = aucune limite ; liste vide = aucune agence
     */
    private static function limiterAgences(Builder $query, string $colonne, ?array $sites): Builder
    {
        if ($sites === null) {
            return $query;
        }

        return $sites === [] ? $query->whereRaw('1 = 0') : $query->whereIn($colonne, $sites);
    }
}
