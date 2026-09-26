<?php

namespace App\Services\Rapports;

use App\Enums\ModePaiement;
use App\Enums\OperateurMobileMoney;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\CompteTresorerie;
use App\Services\Tresorerie\FicheCaisseService;
use App\Support\Rapports\RapportPerimetre;
use Carbon\CarbonImmutable;
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
 *   de l'encaissement — quelle que soit la date de la vente.
 * - Créances : factures impayées ou partielles à l'état actuel, TOUTES dates confondues (les vieilles
 *   dettes restent visibles), agent = créateur de la vente.
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
    ];

    public function __construct(private readonly FicheCaisseService $ficheCaisse) {}

    /**
     * Toutes les sections du rapport (écran : lignes limitées ; export : $limite = null).
     *
     * @return array<string, mixed>
     */
    public function rapport(RapportPerimetre $p, ?int $limite = self::LIMITE_LIGNES): array
    {
        return [
            'ventes' => $this->ventes($p, $limite),
            'encaissements' => $this->encaissements($p, $limite),
            'creances' => $this->creances($p, $limite),
            'mobile_money' => $this->mobileMoney($p, $limite),
            'caisse' => $this->caisse($p),
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
            ->select([
                'fv.id', 'fv.reference', 'fv.created_at', 'fv.montant_net', 'fv.statut_facture',
                ...$this->colonnesNoms(),
            ])
            ->selectRaw($this->encaisseSql().' as encaisse')
            ->orderByDesc('fv.created_at')
            ->when($limite !== null, fn (Builder $q) => $q->limit($limite))
            ->get()
            ->map(fn ($l) => $this->ligneFacture($l));

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

        $parMoyen = $this->encaissementsBase($p)
            ->groupBy('ev.mode_paiement', 'ev.operateur_mobile_money')
            ->selectRaw('ev.mode_paiement, ev.operateur_mobile_money, COUNT(*) as nombre, COALESCE(SUM(ev.montant), 0) as montant')
            ->get()
            ->map(fn ($l) => [
                'cle' => $l->mode_paiement.($l->operateur_mobile_money ? ':'.$l->operateur_mobile_money : ''),
                'libelle' => $this->libelleMoyen((string) $l->mode_paiement, $l->operateur_mobile_money),
                'mode_paiement' => $l->mode_paiement,
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
            ->select([
                'fv.id', 'fv.reference', 'fv.created_at', 'fv.montant_net', 'fv.statut_facture',
                ...$this->colonnesNoms(),
            ])
            ->selectRaw($this->encaisseSql().' as encaisse')
            ->orderBy('fv.created_at')
            ->when($limite !== null, fn (Builder $q) => $q->limit($limite))
            ->get()
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
     * l'obligation), ou déjà utilisée pour le même opérateur n'importe où dans l'organisation —
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
            $cle = $this->cleReference($l['operateur_mobile_money'], $l['reference_paiement']);
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

        $avecEcritures = $p->agentId !== null;

        $fiches = CompteTresorerie::query()
            ->forOrg($p->organizationId)
            ->dediees()
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
            ->when($p->siteIds !== null, fn (Builder $q) => $q->whereIn('fv.site_id', $p->siteIds))
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
            ->select([
                'ev.id', 'ev.date_encaissement', 'ev.created_at', 'ev.montant', 'ev.mode_paiement',
                'ev.operateur_mobile_money', 'ev.reference_paiement',
                'fv.id as facture_id', 'fv.reference as facture_reference',
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
                    'site_nom' => $l->site_nom,
                ];
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
            'site_nom' => $l->site_nom,
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

    private function libelleMoyen(string $mode, ?string $operateur): string
    {
        if ($mode === ModePaiement::MOBILE_MONEY->value) {
            return $operateur !== null && $operateur !== ''
                ? (OperateurMobileMoney::tryFrom($operateur)?->label() ?? $operateur)
                : 'Mobile Money (opérateur non renseigné)';
        }

        return ModePaiement::tryFrom($mode)?->label() ?? $mode;
    }

    private function ordreMoyen(?string $mode): string
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

    private function cleReference(?string $operateur, ?string $reference): ?string
    {
        $ref = mb_strtoupper(trim((string) $reference));

        return $ref === '' ? null : ($operateur ?? '').'|'.$ref;
    }

    /**
     * Toutes les utilisations, dans l'organisation, des références présentes dans la période —
     * regroupées par (opérateur, référence normalisée), seulement celles utilisées plus d'une fois.
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
                    ->get(['e.id', 'e.operateur_mobile_money', 'e.reference_paiement', 'e.date_encaissement', 'e.created_by', 'f.reference as facture_reference', 'f.site_id'])
            );
        }

        return $utilisations
            ->groupBy(fn ($u) => $this->cleReference($u->operateur_mobile_money, $u->reference_paiement))
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
        $cle = $this->cleReference($l->operateur_mobile_money, $l->reference_paiement);

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
