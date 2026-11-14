<?php

namespace App\Services\Rapports;

use App\Enums\ModePaiement;
use App\Enums\OperateurMobileMoney;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\User;
use App\Services\Tresorerie\FicheCaisseService;
use App\Services\Tresorerie\RemisesAgencesService;
use App\Support\Rapports\RapportPerimetre;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moteur unique du rapport d'activité : alimente « Ma situation », le rapport par agence / agent /
 * organisation et les exports (cf. docs/rapports.md). Chaque bloc a sa propre base, volontairement
 * indépendante des autres — ne JAMAIS en déduire un « reste » par différence entre blocs :
 *
 * - Ventes : factures créées dans la période (date d'une vente = création de sa facture), agent =
 *   créateur de la commande. Une vente annulée, annulée pour erreur de saisie ou entièrement
 *   retournée sort du chiffre d'affaires ; un retour partiel l'a déjà réduit (le montant net de la
 *   facture est recalculé par CommandeVenteService::recalculerTotaux()). Encaissé / reste = état
 *   ACTUEL de ces factures, tous encaissements confondus.
 * - Encaissements : encaissements dont la `date_encaissement` est dans la période, agent = auteur
 *   de l'encaissement — quelle que soit la date de la vente. Axe TRÉSORERIE : une agence y voit ce
 *   qu'ELLE a encaissé (`site_encaissement_id`, ADR 0012), y compris pour des commandes d'autres
 *   agences (« Créée à » ≠ « Encaissée à », à reverser), jamais ce qu'une autre agence a encaissé
 *   pour elle.
 *
 * Ventes et créances restent sur l'axe COMMERCIAL : agence de la commande (`factures_ventes.site_id`),
 * avec en information l'agence (ou les agences) où leurs encaissements ont été reçus.
 * - Créances : factures créées dans la période (même date que les ventes) encore impayées ou
 *   partielles à l'état actuel, agent = créateur de la vente. Ce n'est pas l'encours à date (dettes
 *   plus anciennes toujours dues), qui reste une évolution.
 * - Mobile Money : sous-ensemble Mobile Money des encaissements, avec contrôle des références.
 * - Caisse : fiche de chaque caisse dédiée du périmètre (FicheCaisseService, grand livre).
 */
class RapportActiviteService
{
    /** Nombre de lignes affichées par onglet ; les totaux portent toujours sur tout le périmètre. */
    public const LIMITE_LIGNES = 300;

    /**
     * La référence de paiement est obligatoire pour le Mobile Money depuis le 14/09/2026
     * (StoreEncaissementVenteController) : un encaissement plus ancien sans référence n'est pas une
     * anomalie, seulement antérieur à l'obligation.
     */
    public const REFERENCE_OBLIGATOIRE_DEPUIS = '2026-09-14';

    private const STATUTS_COMMANDE_HORS_CA = [
        StatutCommandeVente::ANNULEE,
        StatutCommandeVente::ANNULEE_ERREUR_SAISIE,
        StatutCommandeVente::RETOURNEE,
        // Précommande pas encore remise (ADR 0019) : rien n'est vendu, sa facture n'est que « Créée ».
        StatutCommandeVente::RESERVEE,
        StatutCommandeVente::A_PREPARER,
        StatutCommandeVente::PREPAREE,
    ];

    public function __construct(
        private readonly FicheCaisseService $ficheCaisse,
        private readonly ChargesRapportService $charges,
    ) {}

    /**
     * Toutes les sections du rapport (écran : lignes limitées ; export : $limite = null).
     *
     * - `moyens` : colonnes d'encaissement communes aux trois rapports (colonnesMoyens()) ;
     * - `charges` : charges saisies PAR les agents du périmètre (ChargesRapportService, axe agent) ;
     * - `remise` : remise des agents à LEUR agence (ADR 0064), tirée des fiches de caisse et de compte
     *   Mobile Money — jamais un encaissement compté comme remis ;
     * - `agents` : une ligne par agent (rapport d'activité seulement, pas « Ma situation »).
     *
     * @return array<string, mixed>
     */
    public function rapport(RapportPerimetre $p, ?int $limite = self::LIMITE_LIGNES): array
    {
        $encaissements = $this->encaissements($p, $limite);
        $caisse = $this->caisse($p);
        $comptesMobileMoney = $this->comptesMobileMoney($p);
        $fiches = [...$caisse['fiches'], ...$comptesMobileMoney['fiches']];

        return [
            'ventes' => $this->ventes($p, $limite),
            'encaissements' => $encaissements,
            'creances' => $this->creances($p, $limite),
            'mobile_money' => $this->mobileMoney($p, $limite),
            'caisse' => $caisse,
            'comptes_mobile_money' => $comptesMobileMoney,
            'moyens' => self::colonnesMoyens($encaissements['par_moyen']),
            'charges' => $this->charges->section($p, $limite),
            'remise' => self::remiseAgents($fiches),
            'agents' => $p->maSituation ? null : $this->parAgent($p, $fiches),
        ];
    }

    // ── Moyens d'encaissement : colonnes communes aux trois rapports ─────────

    /**
     * Colonnes de moyens (décision du 14/11/2026, ADR 0063 point 7) : Espèces et Orange Money
     * toujours, les moyens utilisés aujourd'hui. Un autre moyen (Kulu, virement, chèque…) n'a de
     * colonne que s'il a réellement été encaissé : le total encaissé reste la somme des colonnes
     * affichées, sans montant caché. Ordre : espèces, Mobile Money (Orange Money en tête), virement, chèque.
     *
     * @param  iterable<array{mode_paiement: ?string, operateur_mobile_money?: ?string}>  $utilises
     * @return list<array{cle: string, libelle: string}>
     */
    public static function colonnesMoyens(iterable $utilises): array
    {
        $toujours = [
            ['mode_paiement' => ModePaiement::ESPECES->value, 'operateur_mobile_money' => null],
            ['mode_paiement' => ModePaiement::MOBILE_MONEY->value, 'operateur_mobile_money' => OperateurMobileMoney::ORANGE_MONEY->value],
        ];

        return collect($toujours)
            ->concat(collect($utilises)->values())
            ->map(fn (array $m) => [
                'cle' => self::cleMoyen((string) $m['mode_paiement'], $m['operateur_mobile_money'] ?? null),
                'libelle' => self::libelleMoyen((string) $m['mode_paiement'], $m['operateur_mobile_money'] ?? null),
                'ordre' => self::ordreMoyen($m['mode_paiement']).self::ordreOperateur($m['operateur_mobile_money'] ?? null),
            ])
            ->unique('cle')
            ->sortBy(fn (array $m) => $m['ordre'].$m['libelle'])
            ->map(fn (array $m) => ['cle' => $m['cle'], 'libelle' => $m['libelle']])
            ->values()
            ->all();
    }

    /** Rang de l'opérateur dans OperateurMobileMoney (Orange Money d'abord) ; sans opérateur en dernier. */
    private static function ordreOperateur(?string $operateur): string
    {
        $rang = array_search(OperateurMobileMoney::tryFrom((string) $operateur), OperateurMobileMoney::cases(), true);

        return str_pad((string) ($rang === false ? 99 : $rang), 2, '0', STR_PAD_LEFT);
    }

    // ── Remise des agents à leur agence (ADR 0064) ───────────────────────────

    /**
     * Remise des agents à LEUR agence de rattachement (agence de la caisse ou du compte Mobile Money),
     * jamais à la trésorerie principale — qui est le niveau agence (ADR 0016). Tirée des fiches
     * (FicheCaisseService, grand livre et vrais versements / transferts) :
     *
     * - remis      = versements reçus par l'agence (réception confirmée) pendant la période ;
     * - en transit = envoyés ou contestés, réception pas encore confirmée, à ce jour ;
     * - reste      = solde à ce jour des caisses et comptes Mobile Money (il baisse dès l'envoi) ;
     * - statut     = même règle que les agences (RemisesAgencesService::statut()).
     *
     * Null sans aucune caisse dédiée ni compte Mobile Money (rien ne peut être remis).
     *
     * @param  list<array<string, mixed>>  $fiches
     * @return array<string, mixed>|null
     */
    public static function remiseAgents(array $fiches): ?array
    {
        if ($fiches === []) {
            return null;
        }

        $fiches = collect($fiches);
        $remis = round((float) $fiches->sum(fn (array $f) => $f['recus_periode']['montant'] ?? 0.0), 2);
        $enTransit = round((float) $fiches->sum(fn (array $f) => $f['en_cours']['montant'] + $f['contestes']['montant']), 2);
        $reste = round((float) $fiches->sum('solde_actuel'), 2);
        $statut = RemisesAgencesService::statut($reste, $enTransit, $remis);

        return [
            'remis' => $remis,
            'en_transit' => $enTransit,
            'reste_a_remettre' => $reste,
            'agences' => $fiches->pluck('caisse.site_nom')->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'derniere_remise' => $fiches->pluck('dernier_versement.date_envoi')->filter()->max(),
            'statut' => $statut,
            'statut_label' => RemisesAgencesService::LIBELLES[$statut],
        ];
    }

    // ── Une ligne par agent (rapport d'activité) ─────────────────────────────

    /**
     * Une ligne par agent ayant une activité sur la période dans le périmètre (ventes, encaissements,
     * dettes, charges) ou une caisse / un compte Mobile Money concerné. Mêmes bases et mêmes règles
     * que les blocs du rapport (requêtes regroupées par agent) : la somme des lignes redonne les
     * totaux du rapport. Agent = créateur de la vente (ventes, dettes), auteur de l'encaissement,
     * auteur de la dépense (charges), titulaire de la caisse (remise).
     *
     * @param  list<array<string, mixed>>  $fiches
     * @return array{lignes: list<array<string, mixed>>, totaux: array<string, mixed>}
     */
    public function parAgent(RapportPerimetre $p, array $fiches): array
    {
        $vide = ['lignes' => [], 'totaux' => $this->totauxAgents(collect())];
        if ($p->aucuneAgence()) {
            return $vide;
        }

        $debut = $p->debut();
        $fin = $p->fin();
        $cle = fn ($id) => $id === null ? '' : (string) $id;

        $ventes = $this->horsAnnulations($this->ventesBase($p)->whereBetween('fv.created_at', [$debut, $fin]))
            ->groupBy('cv.created_by')
            ->selectRaw('cv.created_by as agent_id, COUNT(*) as nombre, COALESCE(SUM(fv.montant_net), 0) as montant')
            ->get()->keyBy(fn ($l) => $cle($l->agent_id));

        $encaissements = $this->encaissementsBase($p)
            ->groupBy('ev.created_by', 'ev.mode_paiement', 'ev.operateur_mobile_money')
            ->selectRaw('ev.created_by as agent_id, ev.mode_paiement, ev.operateur_mobile_money, COUNT(*) as nombre, COALESCE(SUM(ev.montant), 0) as montant')
            ->get()->groupBy(fn ($l) => $cle($l->agent_id));

        $pourAutres = $this->encaissementsBase($p)
            ->whereColumn('ev.site_encaissement_id', '<>', 'fv.site_id')
            ->groupBy('ev.created_by')
            ->selectRaw('ev.created_by as agent_id, COALESCE(SUM(ev.montant), 0) as montant')
            ->get()->keyBy(fn ($l) => $cle($l->agent_id));

        $dettes = DB::query()
            ->fromSub($this->ventesBase($p)
                ->whereBetween('fv.created_at', [$debut, $fin])
                ->whereIn('fv.statut_facture', [StatutFactureVente::IMPAYEE->value, StatutFactureVente::PARTIEL->value])
                ->select('cv.created_by as agent_id', 'fv.montant_net')
                ->selectRaw($this->encaisseSql().' as encaisse'), 't')
            ->groupBy('agent_id')
            ->selectRaw('agent_id, COUNT(*) as nombre')
            ->selectRaw('COALESCE(SUM(CASE WHEN montant_net - encaisse > 0 THEN montant_net - encaisse ELSE 0 END), 0) as reste')
            ->get()->keyBy(fn ($l) => $cle($l->agent_id));

        $charges = collect($this->charges->parAgent($p));
        $fichesParAgent = collect($fiches)->filter(fn (array $f) => $f['caisse']['agent_id'] !== null)->groupBy(fn (array $f) => (string) $f['caisse']['agent_id']);

        $ids = collect([...$ventes->keys(), ...$encaissements->keys(), ...$dettes->keys(), ...$charges->keys(), ...$fichesParAgent->keys()])
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        $utilisateurs = User::query()->whereIn('id', $ids->filter()->all())->with(['personne', 'sites'])->get()->keyBy('id');

        $lignes = $ids->map(function (string $id) use ($ventes, $encaissements, $pourAutres, $dettes, $charges, $fichesParAgent, $utilisateurs) {
            $parMoyen = collect($encaissements->get($id, []))
                ->map(fn ($l) => [
                    'cle' => self::cleMoyen((string) $l->mode_paiement, $l->operateur_mobile_money),
                    'mode_paiement' => $l->mode_paiement,
                    'operateur_mobile_money' => $l->operateur_mobile_money,
                    'montant' => round((float) $l->montant, 2),
                    'nombre' => (int) $l->nombre,
                ])
                ->groupBy('cle')
                ->map(fn (Collection $m) => [...$m->first(), 'montant' => round((float) $m->sum('montant'), 2), 'nombre' => (int) $m->sum('nombre')]);
            $fichesAgent = $fichesParAgent->get($id, collect())->all();
            $remise = self::remiseAgents($fichesAgent);
            $utilisateur = $utilisateurs->get($id);
            $agences = $remise['agences'] ?? [];
            if ($agences === [] && $utilisateur !== null) {
                $agences = $utilisateur->sites->pluck('nom')->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
            }

            return [
                'agent_id' => $id !== '' ? $id : null,
                'agent' => $utilisateur?->name ?: ($id === '' ? 'Agent non renseigné' : 'Utilisateur sans nom'),
                'agences' => $agences,
                'ventes_nombre' => (int) ($ventes->get($id)?->nombre ?? 0),
                'ventes_montant' => round((float) ($ventes->get($id)?->montant ?? 0), 2),
                'par_moyen' => $parMoyen->all(),
                'encaisse_nombre' => (int) $parMoyen->sum('nombre'),
                'encaisse_montant' => round((float) $parMoyen->sum('montant'), 2),
                'pour_autres_agences' => round((float) ($pourAutres->get($id)?->montant ?? 0), 2),
                'dettes_nombre' => (int) ($dettes->get($id)?->nombre ?? 0),
                'dettes_montant' => round((float) ($dettes->get($id)?->reste ?? 0), 2),
                ...($charges->get($id) ?? ChargesRapportService::VIDES),
                'remise' => $remise,
            ];
        })
            ->sortBy('agent', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return ['lignes' => $lignes->all(), 'totaux' => $this->totauxAgents($lignes)];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lignes
     * @return array<string, mixed>
     */
    private function totauxAgents(Collection $lignes): array
    {
        $somme = fn (string $champ) => round((float) $lignes->sum($champ), 2);
        $remises = $lignes->pluck('remise')->filter();

        return [
            'ventes_nombre' => (int) $lignes->sum('ventes_nombre'),
            'ventes_montant' => $somme('ventes_montant'),
            'par_moyen' => $lignes->flatMap(fn (array $l) => array_values($l['par_moyen']))
                ->groupBy('cle')->map(fn (Collection $m) => round((float) $m->sum('montant'), 2))->all(),
            'encaisse_montant' => $somme('encaisse_montant'),
            'pour_autres_agences' => $somme('pour_autres_agences'),
            'dettes_montant' => $somme('dettes_montant'),
            'charges_saisies' => $somme('charges_saisies'),
            'charges_en_attente' => $somme('charges_en_attente'),
            'remis' => round((float) $remises->sum('remis'), 2),
            'en_transit' => round((float) $remises->sum('en_transit'), 2),
            'reste_a_remettre' => round((float) $remises->sum('reste_a_remettre'), 2),
        ];
    }

    // ── Ventes ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function ventes(RapportPerimetre $p, ?int $limite = self::LIMITE_LIGNES): array
    {
        $periode = fn () => $this->ventesBase($p)->whereBetween('fv.created_at', [$p->debut(), $p->fin()]);
        $valides = fn () => $this->horsAnnulations($periode());

        $resume = DB::query()
            ->fromSub($valides()->select('fv.id', 'fv.montant_net')->selectRaw($this->encaisseSql().' as encaisse'), 't')
            ->selectRaw('COUNT(*) as nombre')
            ->selectRaw('COALESCE(SUM(montant_net), 0) as facture')
            ->selectRaw('COALESCE(SUM(encaisse), 0) as encaisse')
            ->selectRaw('COALESCE(SUM(CASE WHEN montant_net - encaisse > 0 THEN montant_net - encaisse ELSE 0 END), 0) as reste')
            ->first();

        $annulees = $periode()
            ->where(fn (Builder $q) => $q
                ->where('fv.statut_facture', StatutFactureVente::ANNULEE->value)
                ->orWhereIn('cv.statut', $this->valeurs(self::STATUTS_COMMANDE_HORS_CA)))
            ->selectRaw('COUNT(*) as nombre, COALESCE(SUM(fv.montant_net), 0) as montant')
            ->first();

        $lignes = $this->avecNoms($valides(), 'cv.created_by')
            ->leftJoin('vehicules as veh', 'veh.id', '=', 'cv.vehicule_id')
            ->select([
                'fv.id', 'fv.reference', 'fv.created_at', 'fv.montant_net', 'fv.statut_facture',
                'veh.nom_vehicule as vehicule_nom',
                ...$this->colonnesNoms(),
            ])
            ->selectRaw($this->encaisseSql().' as encaisse')
            ->orderByDesc('fv.created_at')
            ->when($limite !== null, fn (Builder $q) => $q->limit($limite))
            ->get();
        $lignes = $this->avecAgencesEncaissement($lignes)->map(fn ($l) => $this->ligneFacture($l));

        return [
            'resume' => [
                'nombre' => (int) $resume->nombre,
                'facture' => round((float) $resume->facture, 2),
                'encaisse' => round((float) $resume->encaisse, 2),
                'reste' => round((float) $resume->reste, 2),
                'annulees_nombre' => (int) $annulees->nombre,
                'annulees_montant' => round((float) $annulees->montant, 2),
            ],
            'lignes' => $lignes->all(),
            'total_lignes' => (int) $resume->nombre,
        ];
    }

    // ── Encaissements ────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function encaissements(RapportPerimetre $p, ?int $limite = self::LIMITE_LIGNES): array
    {
        $resume = $this->encaissementsBase($p)
            ->selectRaw('COUNT(*) as nombre, COALESCE(SUM(ev.montant), 0) as montant')
            ->first();

        // Encaissés par l'agence pour des commandes d'autres agences : à reverser (ADR 0012).
        $pourAutres = $this->encaissementsBase($p)
            ->whereColumn('ev.site_encaissement_id', '<>', 'fv.site_id')
            ->selectRaw('COUNT(*) as nombre, COALESCE(SUM(ev.montant), 0) as montant')
            ->first();

        $parMoyen = $this->encaissementsBase($p)
            ->groupBy('ev.mode_paiement', 'ev.operateur_mobile_money')
            ->selectRaw('ev.mode_paiement, ev.operateur_mobile_money, COUNT(*) as nombre, COALESCE(SUM(ev.montant), 0) as montant')
            ->get()
            ->map(fn ($l) => [
                'cle' => self::cleMoyen((string) $l->mode_paiement, $l->operateur_mobile_money),
                'libelle' => self::libelleMoyen((string) $l->mode_paiement, $l->operateur_mobile_money),
                'mode_paiement' => $l->mode_paiement,
                'operateur_mobile_money' => $l->operateur_mobile_money,
                'nombre' => (int) $l->nombre,
                'montant' => round((float) $l->montant, 2),
            ])
            ->sortBy(fn (array $m) => $this->ordreMoyen($m['mode_paiement']).$m['libelle'])
            ->values();

        $lignes = $this->lignesEncaissements($this->encaissementsBase($p), $limite);

        return [
            'resume' => [
                'nombre' => (int) $resume->nombre,
                'montant' => round((float) $resume->montant, 2),
                'pour_autres_agences_nombre' => (int) $pourAutres->nombre,
                'pour_autres_agences_montant' => round((float) $pourAutres->montant, 2),
            ],
            'par_moyen' => $parMoyen->all(),
            'lignes' => $lignes->all(),
            'total_lignes' => (int) $resume->nombre,
        ];
    }

    // ── Créances ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function creances(RapportPerimetre $p, ?int $limite = self::LIMITE_LIGNES): array
    {
        $base = fn () => $this->ventesBase($p)
            ->whereBetween('fv.created_at', [$p->debut(), $p->fin()])
            ->whereIn('fv.statut_facture', [StatutFactureVente::IMPAYEE->value, StatutFactureVente::PARTIEL->value]);

        $resume = DB::query()
            ->fromSub($base()->select('fv.id', 'fv.montant_net', 'fv.statut_facture', 'fv.created_at')->selectRaw($this->encaisseSql().' as encaisse'), 't')
            ->selectRaw('COUNT(*) as nombre')
            ->selectRaw("COALESCE(SUM(CASE WHEN statut_facture = 'impayee' THEN 1 ELSE 0 END), 0) as impayees")
            ->selectRaw("COALESCE(SUM(CASE WHEN statut_facture = 'partiel' THEN 1 ELSE 0 END), 0) as partielles")
            ->selectRaw('COALESCE(SUM(montant_net), 0) as facture')
            ->selectRaw('COALESCE(SUM(CASE WHEN montant_net - encaisse > 0 THEN montant_net - encaisse ELSE 0 END), 0) as reste')
            ->selectRaw('MIN(created_at) as plus_ancienne')
            ->first();

        $lignes = $this->avecNoms($base(), 'cv.created_by')
            ->leftJoin('vehicules as veh', 'veh.id', '=', 'cv.vehicule_id')
            ->select([
                'fv.id', 'fv.reference', 'fv.created_at', 'fv.montant_net', 'fv.statut_facture',
                'veh.nom_vehicule as vehicule_nom',
                ...$this->colonnesNoms(),
            ])
            ->selectRaw($this->encaisseSql().' as encaisse')
            ->orderBy('fv.created_at')
            ->when($limite !== null, fn (Builder $q) => $q->limit($limite))
            ->get();
        $lignes = $this->avecAgencesEncaissement($lignes)
            ->map(fn ($l) => [
                ...$this->ligneFacture($l),
                'anciennete_jours' => (int) CarbonImmutable::parse($l->created_at)->startOfDay()
                    ->diffInDays(CarbonImmutable::now()->startOfDay()),
            ]);

        return [
            'resume' => [
                'nombre' => (int) $resume->nombre,
                'impayees' => (int) $resume->impayees,
                'partielles' => (int) $resume->partielles,
                'facture' => round((float) $resume->facture, 2),
                'reste' => round((float) $resume->reste, 2),
                'plus_ancienne' => $resume->plus_ancienne ? CarbonImmutable::parse($resume->plus_ancienne)->toDateString() : null,
            ],
            'lignes' => $lignes->all(),
            'total_lignes' => (int) $resume->nombre,
        ];
    }

    // ── Mobile Money ─────────────────────────────────────────────────────────

    /**
     * Encaissements Mobile Money de la période et contrôle de leurs références : absente (après
     * l'obligation), ou déjà utilisée (tous opérateurs confondus, ADR 0014) n'importe où dans l'organisation —
     * même hors période, agence ou agent filtrés. Le détail d'une autre utilisation n'est montré que
     * si elle est dans le périmètre de l'utilisateur ; sinon elle est seulement comptée.
     *
     * @return array<string, mixed>
     */
    public function mobileMoney(RapportPerimetre $p, ?int $limite = self::LIMITE_LIGNES): array
    {
        $base = fn () => $this->encaissementsBase($p)->where('ev.mode_paiement', ModePaiement::MOBILE_MONEY->value);

        $tous = $base()->get(['ev.id', 'ev.operateur_mobile_money', 'ev.reference_paiement', 'ev.created_at']);
        $doublons = $this->doublonsReferences($p, $tous);

        $anomalies = $tous->mapWithKeys(fn ($l) => [$l->id => $this->anomalie($l, $doublons)]);

        $parOperateur = $tous->isEmpty() ? collect() : $base()
            ->groupBy('ev.operateur_mobile_money')
            ->selectRaw('ev.operateur_mobile_money, COUNT(*) as nombre, COALESCE(SUM(ev.montant), 0) as montant')
            ->get()
            ->map(fn ($l) => [
                'operateur' => $l->operateur_mobile_money,
                'libelle' => $this->libelleMoyen(ModePaiement::MOBILE_MONEY->value, $l->operateur_mobile_money),
                'nombre' => (int) $l->nombre,
                'montant' => round((float) $l->montant, 2),
            ])
            ->sortBy('libelle')
            ->values();

        $lignes = $this->lignesEncaissements($base(), $limite)->map(function (array $l) use ($anomalies, $doublons) {
            $cle = $this->cleReference($l['reference_paiement']);
            $autres = $cle !== null ? ($doublons[$cle] ?? collect())->reject(fn (array $d) => $d['id'] === $l['id']) : collect();

            return [
                ...$l,
                'anomalie' => $anomalies[$l['id']] ?? null,
                'autres_utilisations' => $autres->where('visible', true)->values()->all(),
                'autres_hors_perimetre' => $autres->where('visible', false)->count(),
            ];
        });

        $compte = $anomalies->countBy(fn (?string $a) => $a ?? 'aucune');

        return [
            'resume' => [
                'nombre' => $tous->count(),
                'montant' => round((float) $base()->sum('ev.montant'), 2),
                'reference_absente' => (int) ($compte['reference_absente'] ?? 0),
                'reference_dupliquee' => (int) ($compte['reference_dupliquee'] ?? 0),
                'anterieure_obligation' => (int) ($compte['anterieure_obligation'] ?? 0),
            ],
            'par_operateur' => $parOperateur->all(),
            'lignes' => $lignes->all(),
            'total_lignes' => $tous->count(),
        ];
    }

    // ── Caisse ───────────────────────────────────────────────────────────────

    /**
     * Caisses dédiées du périmètre : actives, ou encore concernées par la période (solde, mouvement
     * ou versement en attente). Détail des écritures seulement quand un agent est ciblé (Ma situation,
     * filtre Agent) — la vue agence reste un tableau d'une ligne par caisse.
     *
     * @return array<string, mixed>
     */
    public function caisse(RapportPerimetre $p): array
    {
        if ($p->aucuneAgence()) {
            return $this->caisseVide($p);
        }

        return $this->synthese($p, CompteTresorerie::query()
            ->forOrg($p->organizationId)
            // Caisses en espèces seulement : le compte Mobile Money d'un agent (ADR 0027) a son
            // propre bloc (comptesMobileMoney()).
            ->caissesDediees());
    }

    /**
     * Comptes Mobile Money des agents (ADR 0027, lot 4) : même synthèse que les caisses — fonds
     * encore détenus par l'agent (solde), transferts envoyés à l'agence, en cours, frais — tirée du
     * grand livre par FicheCaisseService. Mêmes clés que caisse() pour un seul affichage.
     *
     * @return array<string, mixed>
     */
    public function comptesMobileMoney(RapportPerimetre $p): array
    {
        if ($p->aucuneAgence()) {
            return $this->caisseVide($p);
        }

        return $this->synthese($p, CompteTresorerie::query()->forOrg($p->organizationId)->mobileMoneyDedies());
    }

    /**
     * @param  EloquentBuilder<CompteTresorerie>  $supports
     * @return array<string, mixed>
     */
    private function synthese(RapportPerimetre $p, EloquentBuilder $supports): array
    {
        $avecEcritures = $p->agentId !== null;

        $fiches = $supports
            ->when($p->siteIds !== null, fn ($q) => $q->whereIn('site_id', $p->siteIds))
            ->when($p->agentId !== null, fn ($q) => $q->where('agent_id', $p->agentId))
            ->get()
            ->map(fn (CompteTresorerie $c) => $this->ficheCaisse->pour($c, $p->debut(), $p->fin(), $avecEcritures))
            ->filter(fn (array $f) => $f['caisse']['actif']
                || abs($f['solde_actuel']) >= 0.005
                || abs($f['solde_debut']) >= 0.005
                || $f['mouvements'] !== []
                || $f['en_cours']['nombre'] > 0
                || $f['contestes']['nombre'] > 0)
            ->sortBy(fn (array $f) => ($f['caisse']['agent_nom'] ?? '').' '.($f['caisse']['site_nom'] ?? ''), SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            'aucune_caisse' => $fiches->isEmpty(),
            'detail' => $avecEcritures,
            'resume' => [
                'solde_debut' => round((float) $fiches->sum('solde_debut'), 2),
                'entrees' => round((float) $fiches->sum('total_entrees'), 2),
                'sorties' => round((float) $fiches->sum('total_sorties'), 2),
                'solde_fin' => round((float) $fiches->sum('solde_fin'), 2),
                'solde_actuel' => round((float) $fiches->sum('solde_actuel'), 2),
                'en_cours_montant' => round((float) $fiches->sum(fn (array $f) => $f['en_cours']['montant']), 2),
                'en_cours_nombre' => (int) $fiches->sum(fn (array $f) => $f['en_cours']['nombre']),
                'contestes_nombre' => (int) $fiches->sum(fn (array $f) => $f['contestes']['nombre']),
            ],
            'fiches' => $fiches->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function caisseVide(RapportPerimetre $p): array
    {
        return [
            'aucune_caisse' => true,
            'detail' => $p->agentId !== null,
            'resume' => [
                'solde_debut' => 0.0, 'entrees' => 0.0, 'sorties' => 0.0, 'solde_fin' => 0.0,
                'solde_actuel' => 0.0, 'en_cours_montant' => 0.0, 'en_cours_nombre' => 0, 'contestes_nombre' => 0,
            ],
            'fiches' => [],
        ];
    }

    // ── Bases de requête ─────────────────────────────────────────────────────

    private function ventesBase(RapportPerimetre $p): Builder
    {
        return DB::table('factures_ventes as fv')
            ->join('commandes_ventes as cv', 'cv.id', '=', 'fv.commande_vente_id')
            ->where('fv.organization_id', $p->organizationId)
            ->whereNull('fv.deleted_at')
            ->whereNull('cv.deleted_at')
            ->when($p->siteIds !== null, fn (Builder $q) => $q->whereIn('fv.site_id', $p->siteIds))
            ->when($p->agentId !== null, fn (Builder $q) => $q->where('cv.created_by', $p->agentId));
    }

    private function horsAnnulations(Builder $q): Builder
    {
        return $q
            ->where('fv.statut_facture', '<>', StatutFactureVente::ANNULEE->value)
            ->whereNotIn('cv.statut', $this->valeurs(self::STATUTS_COMMANDE_HORS_CA));
    }

    private function encaissementsBase(RapportPerimetre $p): Builder
    {
        return DB::table('encaissements_ventes as ev')
            ->join('factures_ventes as fv', 'fv.id', '=', 'ev.facture_vente_id')
            ->leftJoin('commandes_ventes as cv', 'cv.id', '=', 'fv.commande_vente_id')
            ->where('fv.organization_id', $p->organizationId)
            ->whereNull('fv.deleted_at')
            // Axe trésorerie : l'agence qui a reçu l'argent (ADR 0012) — un encaissement antérieur sans
            // agence d'encaissement renseignée reste à l'agence de sa facture.
            ->when($p->siteIds !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereIn('ev.site_encaissement_id', $p->siteIds)
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('ev.site_encaissement_id')->whereIn('fv.site_id', $p->siteIds))))
            ->when($p->agentId !== null, fn (Builder $q) => $q->where('ev.created_by', $p->agentId))
            ->whereBetween('ev.date_encaissement', [$p->debut()->toDateString(), $p->fin()->toDateString()]);
    }

    private function encaisseSql(): string
    {
        return '(SELECT COALESCE(SUM(e.montant), 0) FROM encaissements_ventes e WHERE e.facture_vente_id = fv.id)';
    }

    /** Agence, client (de la commande) et agent (colonne donnée) — noms assemblés en PHP (SQLite/MySQL). */
    private function avecNoms(Builder $q, string $colonneAgent): Builder
    {
        return $q
            ->leftJoin('sites as s', 's.id', '=', 'fv.site_id')
            ->leftJoin('clients as c', 'c.id', '=', 'cv.client_id')
            ->leftJoin('users as u', 'u.id', '=', $colonneAgent)
            ->leftJoin('personnes as pa', 'pa.id', '=', 'u.personne_id');
    }

    /** @return list<string> */
    private function colonnesNoms(): array
    {
        return [
            's.nom as site_nom',
            'c.nom_complet as client_nom_complet', 'c.prenom as client_prenom', 'c.nom as client_nom',
            'u.id as agent_id', 'pa.prenom as agent_prenom', 'pa.nom as agent_nom',
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function lignesEncaissements(Builder $base, ?int $limite): Collection
    {
        return $this->avecNoms($base, 'ev.created_by')
            ->leftJoin('sites as se', 'se.id', '=', 'ev.site_encaissement_id')
            ->select([
                'ev.id', 'ev.date_encaissement', 'ev.created_at', 'ev.montant', 'ev.mode_paiement',
                'ev.operateur_mobile_money', 'ev.reference_paiement',
                'fv.id as facture_id', 'fv.reference as facture_reference',
                'se.nom as encaisse_a',
                ...$this->colonnesNoms(),
            ])
            ->orderByDesc('ev.date_encaissement')
            ->orderByDesc('ev.created_at')
            ->when($limite !== null, fn (Builder $q) => $q->limit($limite))
            ->get()
            ->map(function ($l) {
                $date = CarbonImmutable::parse($l->date_encaissement)->toDateString();
                $saisiLe = $l->created_at ? CarbonImmutable::parse($l->created_at) : null;

                return [
                    'id' => $l->id,
                    'date_encaissement' => $date,
                    'saisi_le' => $saisiLe?->format('Y-m-d H:i'),
                    'saisie_differee' => $saisiLe !== null && $saisiLe->toDateString() !== $date,
                    'montant' => round((float) $l->montant, 2),
                    'mode_paiement' => $l->mode_paiement,
                    'operateur_mobile_money' => $l->operateur_mobile_money,
                    'moyen_libelle' => $this->libelleMoyen((string) $l->mode_paiement, $l->operateur_mobile_money),
                    'reference_paiement' => $l->reference_paiement,
                    'facture_id' => $l->facture_id,
                    'facture_reference' => $l->facture_reference,
                    'client' => $this->nomClient($l),
                    'agent' => $this->nomAgent($l),
                    // « Créée à » (agence de la commande) et « Encaissée à » (agence qui a reçu l'argent).
                    'site_nom' => $l->site_nom,
                    'encaisse_a' => $l->encaisse_a ?? $l->site_nom,
                    'pour_autre_agence' => $l->encaisse_a !== null && $l->site_nom !== null && $l->encaisse_a !== $l->site_nom,
                ];
            });
    }

    /**
     * Agences où chaque facture a été encaissée (« Encaissée à »), en une requête : noms distincts,
     * dans l'ordre alphabétique — une facture payée en plusieurs fois peut l'être dans deux agences.
     *
     * @param  Collection<int, object>  $lignes
     * @return Collection<int, object>
     */
    private function avecAgencesEncaissement(Collection $lignes): Collection
    {
        if ($lignes->isEmpty()) {
            return $lignes;
        }

        $parFacture = DB::table('encaissements_ventes as ev')
            ->join('factures_ventes as fv', 'fv.id', '=', 'ev.facture_vente_id')
            ->leftJoin('sites as se', 'se.id', '=', DB::raw('COALESCE(ev.site_encaissement_id, fv.site_id)'))
            ->whereIn('ev.facture_vente_id', $lignes->pluck('id')->all())
            ->distinct()
            ->get(['ev.facture_vente_id', 'se.nom'])
            ->groupBy('facture_vente_id')
            ->map(fn (Collection $sites) => $sites->pluck('nom')->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->implode(', '));

        return $lignes->each(function (object $l) use ($parFacture) {
            $l->encaisse_a = $parFacture->get($l->id) ?: null;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function ligneFacture(object $l): array
    {
        $encaisse = round((float) $l->encaisse, 2);
        $statut = StatutFactureVente::tryFrom((string) $l->statut_facture);

        return [
            'id' => $l->id,
            'reference' => $l->reference,
            'date' => CarbonImmutable::parse($l->created_at)->toDateString(),
            'client' => $this->nomClient($l),
            'agent' => $this->nomAgent($l),
            'vehicule_nom' => $l->vehicule_nom,
            // « Créée à » : agence de la commande ; « Encaissée à » : agence(s) de ses encaissements.
            'site_nom' => $l->site_nom,
            'encaisse_a' => $l->encaisse_a ?? null,
            'montant' => round((float) $l->montant_net, 2),
            'encaisse' => $encaisse,
            'reste' => round(max(0, (float) $l->montant_net - $encaisse), 2),
            'statut' => $statut?->value ?? (string) $l->statut_facture,
            'statut_label' => $statut?->label() ?? (string) $l->statut_facture,
        ];
    }

    private function nomClient(object $l): ?string
    {
        $saisi = trim((string) ($l->client_nom_complet ?? ''));
        $nom = $saisi !== '' ? $saisi : trim(($l->client_prenom ?? '').' '.($l->client_nom ?? ''));

        return $nom !== '' ? $nom : null;
    }

    private function nomAgent(object $l): ?string
    {
        $nom = trim(($l->agent_prenom ?? '').' '.($l->agent_nom ?? ''));

        return $nom !== '' ? $nom : null;
    }

    /** Clé d'un moyen dans les totaux par moyen : `especes`, `mobile_money:orange_money`… (aussi pour le rapport par agence). */
    public static function cleMoyen(string $mode, ?string $operateur): string
    {
        return $mode.($operateur !== null && $operateur !== '' ? ':'.$operateur : '');
    }

    public static function libelleMoyen(string $mode, ?string $operateur): string
    {
        if ($mode === ModePaiement::MOBILE_MONEY->value) {
            return $operateur !== null && $operateur !== ''
                ? (OperateurMobileMoney::tryFrom($operateur)?->label() ?? $operateur)
                : 'Mobile Money (opérateur non renseigné)';
        }

        return ModePaiement::tryFrom($mode)?->label() ?? $mode;
    }

    /** Ordre d'affichage des moyens : espèces, Mobile Money, virement, chèque (aussi pour le rapport par agence). */
    public static function ordreMoyen(?string $mode): string
    {
        return match ($mode) {
            ModePaiement::ESPECES->value => '1',
            ModePaiement::MOBILE_MONEY->value => '2',
            ModePaiement::VIREMENT->value => '3',
            ModePaiement::CHEQUE->value => '4',
            default => '9',
        };
    }

    // ── Références Mobile Money ──────────────────────────────────────────────

    private function cleReference(?string $reference): ?string
    {
        return EncaissementVente::normaliserReference($reference);
    }

    /**
     * Toutes les utilisations, dans l'organisation, des références présentes dans la période —
     * regroupées par référence normalisée (tous opérateurs confondus, ADR 0014), seulement celles utilisées plus d'une fois.
     *
     * @param  Collection<int, object>  $lignes
     * @return array<string, Collection<int, array<string, mixed>>>
     */
    private function doublonsReferences(RapportPerimetre $p, Collection $lignes): array
    {
        $references = $lignes
            ->map(fn ($l) => mb_strtoupper(trim((string) $l->reference_paiement)))
            ->filter(fn (string $r) => $r !== '')
            ->unique()
            ->values();

        if ($references->isEmpty()) {
            return [];
        }

        $utilisations = collect();
        foreach ($references->chunk(500) as $lot) {
            $utilisations = $utilisations->merge(
                DB::table('encaissements_ventes as e')
                    ->join('factures_ventes as f', 'f.id', '=', 'e.facture_vente_id')
                    ->where('f.organization_id', $p->organizationId)
                    ->whereNull('f.deleted_at')
                    ->where('e.mode_paiement', ModePaiement::MOBILE_MONEY->value)
                    ->whereIn(DB::raw('UPPER(TRIM(e.reference_paiement))'), $lot->all())
                    ->get(['e.id', 'e.operateur_mobile_money', 'e.reference_paiement', 'e.date_encaissement', 'e.created_by', 'f.reference as facture_reference', DB::raw('COALESCE(e.site_encaissement_id, f.site_id) as site_id')])
            );
        }

        return $utilisations
            ->groupBy(fn ($u) => $this->cleReference($u->reference_paiement))
            ->filter(fn (Collection $groupe) => $groupe->count() > 1)
            ->map(fn (Collection $groupe) => $groupe->map(fn ($u) => [
                'id' => $u->id,
                'facture_reference' => $u->facture_reference,
                'date_encaissement' => CarbonImmutable::parse($u->date_encaissement)->toDateString(),
                'visible' => ($p->siteIds === null || in_array($u->site_id, $p->siteIds, true))
                    && ($p->agentId === null || $u->created_by === $p->agentId),
            ]))
            ->all();
    }

    /**
     * @param  array<string, Collection<int, array<string, mixed>>>  $doublons
     */
    private function anomalie(object $l, array $doublons): ?string
    {
        $cle = $this->cleReference($l->reference_paiement);

        if ($cle === null) {
            $saisiLe = $l->created_at ? CarbonImmutable::parse($l->created_at) : null;

            return $saisiLe !== null && $saisiLe->lessThan(CarbonImmutable::parse(self::REFERENCE_OBLIGATOIRE_DEPUIS))
                ? 'anterieure_obligation'
                : 'reference_absente';
        }

        return isset($doublons[$cle]) ? 'reference_dupliquee' : null;
    }

    /**
     * @param  list<\BackedEnum>  $enums
     * @return list<string|int>
     */
    private function valeurs(array $enums): array
    {
        return array_map(fn (\BackedEnum $e) => $e->value, $enums);
    }
}
