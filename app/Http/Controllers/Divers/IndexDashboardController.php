<?php

namespace App\Http\Controllers\Divers;

use App\Enums\ClientType;
use App\Enums\ModeRemiseGrossiste;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Http\Controllers\Controller;
use App\Models\CommissionProcessus;
use App\Models\FactureVente;
use App\Models\Site;
use App\Services\Client\QrPayloadResolver;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\SiteScopeService;
use App\Support\Periodes\Periode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IndexDashboardController extends Controller
{
    public function __construct(
        private readonly QrPayloadResolver $qrPayloadResolver,
        private readonly SiteScopeService $siteScope,
    ) {}

    /** Périodes du tableau de bord : le standard sans dates libres, plus trimestres et semestres (ADR 0057). */
    private const PERIODES = [...Periode::RAPIDES, ...Periode::TRIMESTRES_SEMESTRES, Periode::TOUT];

    public function __invoke(Request $request): Response
    {
        $user = auth()->user();
        $orgId = $user->organization_id;
        $periode = Periode::depuisRequeteValidee($request, 'ce_mois', self::PERIODES);

        // Périmètre d'agence : même mécanisme que la trésorerie et les rapports (SiteScopeService) —
        // toute l'organisation pour un administrateur, ses agences (user_sites) sinon. Une facture sans
        // agence n'est comptée que dans la vue organisation (cf. docs/rapports.md, tableau de bord).
        $siteIds = $user->voitToutesLesAgences()
            ? null
            : $this->siteScope->accessibleSiteIds($user)->map(fn ($id) => (string) $id)->all();
        $perimetre = fn ($query, string $colonne) => $siteIds === null ? $query : $query->whereIn($colonne, $siteIds);

        // ── Agrégats des factures de vente ─────────────────────────────────────
        $statsQuery = $perimetre(FactureVente::where('organization_id', $orgId), 'site_id');
        if ($periode->estBornee()) {
            $periode->appliquer($statsQuery, 'created_at');
        }

        $row = $statsQuery->selectRaw("
                COUNT(*) as total_count,
                COALESCE(SUM(montant_net), 0) as total_montant,
                COALESCE(SUM(CASE WHEN statut_facture = 'payee'    THEN 1 ELSE 0 END), 0) as payees_count,
                COALESCE(SUM(CASE WHEN statut_facture = 'payee'    THEN montant_net ELSE 0 END), 0) as payees_montant,
                COALESCE(SUM(CASE WHEN statut_facture = 'impayee'  THEN 1 ELSE 0 END), 0) as impayees_count,
                COALESCE(SUM(CASE WHEN statut_facture = 'annulee'  THEN 1 ELSE 0 END), 0) as annulees_count,
                COALESCE(SUM(CASE WHEN statut_facture IN ('impayee','partiel') THEN montant_net ELSE 0 END), 0) as montant_actif
            ")
            ->first();

        // Encaissé sur les factures encore actives (impayée + partielle)
        $encaisseQuery = DB::table('encaissements_ventes as ev')
            ->join('factures_ventes as fv', 'fv.id', '=', 'ev.facture_vente_id')
            ->where('fv.organization_id', $orgId)
            ->whereNull('fv.deleted_at')
            ->when($siteIds !== null, fn ($q) => $q->whereIn('fv.site_id', $siteIds))
            ->whereIn('fv.statut_facture', [
                StatutFactureVente::IMPAYEE->value,
                StatutFactureVente::PARTIEL->value,
            ]);
        if ($periode->estBornee()) {
            $periode->appliquer($encaisseQuery, 'fv.created_at');
        }
        $encaisseActif = $encaisseQuery->sum('ev.montant');

        $resteAEncaisser = max(0, (float) $row->montant_actif - (float) $encaisseActif);

        // ── Évolution mensuelle (année courante) ───────────────────────────────
        // MONTH() n'existe pas sous SQLite (tests CI) → strftime('%m', ...) à la place
        $monthExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%m', created_at) AS INTEGER)"
            : 'MONTH(created_at)';

        $monthlyQuery = $perimetre(FactureVente::where('organization_id', $orgId), 'site_id');
        if ($periode->estBornee()) {
            $periode->appliquer($monthlyQuery, 'created_at');
        } else {
            Periode::rapide('cette_annee', CarbonImmutable::now())->appliquer($monthlyQuery, 'created_at');
        }

        $monthly = $monthlyQuery
            ->selectRaw("
                {$monthExpr} as mois,
                COALESCE(SUM(CASE WHEN statut_facture = 'payee'   THEN montant_net ELSE 0 END), 0) as payees,
                COALESCE(SUM(CASE WHEN statut_facture = 'partiel' THEN montant_net ELSE 0 END), 0) as partielles,
                COALESCE(SUM(CASE WHEN statut_facture = 'impayee' THEN montant_net ELSE 0 END), 0) as impayees
            ")
            ->groupBy(DB::raw($monthExpr))
            ->get()
            ->keyBy('mois');

        $evolutionMensuelle = collect(range(1, 12))->map(fn ($m) => [
            'payees' => (float) ($monthly->get($m)?->payees ?? 0),
            'partielles' => (float) ($monthly->get($m)?->partielles ?? 0),
            'impayees' => (float) ($monthly->get($m)?->impayees ?? 0),
        ])->values()->toArray();

        // ── Évolution journalière (60 derniers jours) ─────────────────────────
        // Couvre aujourd'hui, hier, cette semaine, semaine préc., ce mois, mois préc.
        $dailyQuery = $perimetre(FactureVente::where('organization_id', $orgId), 'site_id');
        if ($periode->estBornee()) {
            $periode->appliquer($dailyQuery, 'created_at');
        } else {
            $dailyQuery->where('created_at', '>=', now()->subDays(59)->startOfDay());
        }

        $dailyRows = $dailyQuery
            ->selectRaw("
                DATE(created_at) as date,
                COALESCE(SUM(CASE WHEN statut_facture = 'payee'   THEN montant_net ELSE 0 END), 0) as payees,
                COALESCE(SUM(CASE WHEN statut_facture = 'partiel' THEN montant_net ELSE 0 END), 0) as partielles,
                COALESCE(SUM(CASE WHEN statut_facture = 'impayee' THEN montant_net ELSE 0 END), 0) as impayees
            ")
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        // index 0 = il y a 59 jours, index 59 = aujourd'hui
        $evolutionQuotidienne = collect(range(59, 0))->map(function ($daysAgo) use ($dailyRows) {
            $date = now()->subDays($daysAgo)->toDateString();
            $row = $dailyRows->get($date);

            return [
                'date' => $date,
                'payees' => (float) ($row?->payees ?? 0),
                'partielles' => (float) ($row?->partielles ?? 0),
                'impayees' => (float) ($row?->impayees ?? 0),
            ];
        })->values()->toArray();

        // ── CA par site (factures non annulées, site renseigné) ───────────────
        $caParSite = $perimetre(FactureVente::where('factures_ventes.organization_id', $orgId), 'factures_ventes.site_id')
            ->where('factures_ventes.statut_facture', '!=', StatutFactureVente::ANNULEE->value)
            ->whereNotNull('factures_ventes.site_id')
            ->join('sites', function ($join) {
                $join->on('sites.id', '=', 'factures_ventes.site_id')
                    ->whereNull('sites.deleted_at');
            });
        if ($periode->estBornee()) {
            $periode->appliquer($caParSite, 'factures_ventes.created_at');
        }
        $caParSite = $caParSite
            ->selectRaw('sites.nom, COALESCE(SUM(factures_ventes.montant_net), 0) as montant')
            ->groupBy('sites.id', 'sites.nom')
            ->orderByDesc('montant')
            ->get()
            ->map(fn ($r) => ['nom' => $r->nom, 'montant' => (float) $r->montant])
            ->values()
            ->toArray();

        // ── CA par type de véhicule (factures non annulées, véhicule renseigné) ─
        $caParTypeVehicule = $perimetre(FactureVente::where('factures_ventes.organization_id', $orgId), 'factures_ventes.site_id')
            ->where('factures_ventes.statut_facture', '!=', StatutFactureVente::ANNULEE->value)
            ->whereNotNull('factures_ventes.vehicule_id')
            ->join('vehicules', function ($join) {
                $join->on('vehicules.id', '=', 'factures_ventes.vehicule_id')
                    ->whereNull('vehicules.deleted_at');
            })
            ->leftJoin('type_vehicules', function ($join) {
                $join->on('type_vehicules.id', '=', 'vehicules.type_vehicule_id')
                    ->whereNull('type_vehicules.deleted_at');
            });
        if ($periode->estBornee()) {
            $periode->appliquer($caParTypeVehicule, 'factures_ventes.created_at');
        }
        $caParTypeVehicule = $caParTypeVehicule
            ->selectRaw('type_vehicules.nom as type_nom, COALESCE(SUM(factures_ventes.montant_net), 0) as montant')
            ->groupBy('type_vehicules.id', 'type_vehicules.nom')
            ->orderByDesc('montant')
            ->get()
            ->map(fn ($r) => [
                'label' => $r->type_nom ?? '—',
                'montant' => (float) $r->montant,
            ])
            ->values()
            ->toArray();

        // ── CA par produit (lignes de commandes non annulées) ─────────────────
        $caParProduit = DB::table('commande_vente_lignes as cvl')
            ->join('commandes_ventes as cv', 'cv.id', '=', 'cvl.commande_vente_id')
            ->join('produit_variantes as pv', 'pv.id', '=', 'cvl.variante_id')
            ->join('produits as p', 'p.id', '=', 'pv.produit_id')
            ->where('cv.organization_id', $orgId)
            ->when($siteIds !== null, fn ($q) => $q->whereIn('cv.site_id', $siteIds))
            ->whereNull('cv.deleted_at')
            ->whereNull('pv.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereNotIn('cv.statut', [StatutCommandeVente::ANNULEE->value, StatutCommandeVente::ANNULEE_ERREUR_SAISIE->value]);
        if ($periode->estBornee()) {
            $periode->appliquer($caParProduit, 'cv.created_at');
        }
        $caParProduit = $caParProduit
            ->selectRaw('p.nom as nom, COALESCE(SUM(cvl.total_ligne), 0) as total')
            ->groupBy('p.id', 'p.nom')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['nom' => $r->nom, 'total' => (float) $r->total])
            ->values()
            ->toArray();

        // ── CA par processus (mêmes factures que le CA par site, sans exiger d'agence) ──
        // Le processus n'est pas stocké : il est dérivé de la commande par
        // CommissionProcessusDefaults::identiteCodePourVente(), la même source que la colonne
        // « Processus » de la liste des ventes et la génération des commissions. On agrège donc
        // par triplet (nature, type de client, mode de remise) puis on résout le code en PHP.
        $caParProcessusQuery = DB::table('factures_ventes as fv')
            ->join('commandes_ventes as cv', 'cv.id', '=', 'fv.commande_vente_id')
            ->leftJoin('clients as c', 'c.id', '=', 'cv.client_id')
            ->where('fv.organization_id', $orgId)
            ->whereNull('fv.deleted_at')
            ->whereNull('cv.deleted_at')
            ->when($siteIds !== null, fn ($q) => $q->whereIn('fv.site_id', $siteIds))
            ->where('fv.statut_facture', '!=', StatutFactureVente::ANNULEE->value);
        if ($periode->estBornee()) {
            $periode->appliquer($caParProcessusQuery, 'fv.created_at');
        }

        $caParProcessus = collect([
            CommissionProcessus::CODE_VENTE,
            CommissionProcessus::CODE_DISTRIBUTION_CLIENT,
            CommissionProcessus::CODE_TRANSFERT_GROSSISTE,
        ])->mapWithKeys(fn (string $code) => [$code => ['montant' => 0.0, 'nb_factures' => 0]])->all();

        $caParProcessusQuery
            ->selectRaw('cv.nature_operation, c.type as client_type, cv.mode_remise_grossiste, COUNT(*) as nb_factures, COALESCE(SUM(fv.montant_net), 0) as montant')
            ->groupBy('cv.nature_operation', 'c.type', 'cv.mode_remise_grossiste')
            ->get()
            ->each(function ($r) use (&$caParProcessus) {
                $code = CommissionProcessusDefaults::identiteCodePourVente(
                    NatureOperation::tryFrom((string) $r->nature_operation) ?? NatureOperation::VENTE_STANDARD,
                    ClientType::tryFrom((string) $r->client_type),
                    ModeRemiseGrossiste::tryFrom((string) $r->mode_remise_grossiste),
                );
                $caParProcessus[$code]['montant'] += (float) $r->montant;
                $caParProcessus[$code]['nb_factures'] += (int) $r->nb_factures;
            });

        $caParProcessus = collect($caParProcessus)
            ->map(fn (array $totaux, string $code) => [
                'code' => $code,
                'label' => CommissionProcessusDefaults::libelle($code),
                'montant' => $totaux['montant'],
                'nb_factures' => $totaux['nb_factures'],
            ])
            ->values()
            ->toArray();

        return Inertia::render('Dashboard', [
            'periode' => $periode->cle,
            // Bornes de chaque choix, résolues ici : le graphique ne recalcule aucune date.
            'periodes' => Periode::optionsAvecBornes(self::PERIODES),
            // null = toute l'organisation ; sinon les agences auxquelles les chiffres sont limités.
            'agences' => $siteIds === null
                ? null
                : Site::whereIn('id', $siteIds)->orderBy('nom')->pluck('nom')->values()->all(),
            // QR remplaçant les initiales sur mobile (cf. HeaderWidget.vue) — même
            // résolveur que l'espace client/l'API mobile (App\Services\Client\
            // QrPayloadResolver), `null` si l'utilisateur n'a aucun profil
            // propriétaire/livreur réellement rattaché (staff pur).
            'qr_payload' => $this->qrPayloadResolver->resolveForUser($request->user()),
            'stats_factures' => [
                'total_count' => (int) $row->total_count,
                'total_montant' => (float) $row->total_montant,
                'payees_count' => (int) $row->payees_count,
                'payees_montant' => (float) $row->payees_montant,
                'total_encaisse' => (float) $row->payees_montant + (float) $encaisseActif,
                'impayees_count' => (int) $row->impayees_count,
                'annulees_count' => (int) $row->annulees_count,
                'reste_a_encaisser' => $resteAEncaisser,
            ],
            'evolution_mensuelle' => $evolutionMensuelle,
            'evolution_quotidienne' => $evolutionQuotidienne,
            'ca_par_site' => $caParSite,
            'ca_par_type_vehicule' => $caParTypeVehicule,
            'ca_par_produit' => $caParProduit,
            'ca_par_processus' => $caParProcessus,
        ]);
    }
}
