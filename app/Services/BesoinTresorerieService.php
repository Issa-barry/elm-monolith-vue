<?php

namespace App\Services;

use App\Enums\TypePeriodePaiement;
use App\Models\PaieLigne;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\Site;

/**
 * Prévision du montant que le siège doit prévoir pour chaque agence, pour un
 * mois donné, afin qu'elle puisse régler ses commissions livreurs (2
 * quinzaines), ses commissions propriétaires et ses salaires.
 *
 * Socle : le système transversal PaiementPeriode/PaiementFiche pour
 * livreurs/propriétaires (cf. PeriodeCalculatorService) — SAUF pour les
 * salaires, sourcés directement sur PaieLigne/PaiePeriode. Deux raisons à
 * cet écart, toutes deux vérifiées dans le code existant :
 *
 *  1. TypePeriodePaiement::periodicity() vaut "quinzaine" pour LES TROIS
 *     types de bénéficiaires, y compris SALARIE — l'écran Comptabilité >
 *     Périodes permet donc de calculer indépendamment un P1 et un P2
 *     "salarié" sur le même mois. Or PeriodeCalculatorService::calculerSalaries()
 *     sélectionne les PaieLigne par MOIS entier (whereMonth), pas par plage
 *     de dates de la période : si les deux quinzaines "salarié" d'un même
 *     mois étaient calculées, chacune générerait une PaiementFiche portant
 *     l'intégralité du salaire mensuel — un double comptage si les deux
 *     étaient sommées. PaieLigne (un employé × un mois, contrainte unique
 *     paie_periode_id+employe_id) n'a pas cette ambiguïté.
 *  2. Le calcul salarial existant (prorata, primes/avances/retenues) vit
 *     dans PaieCalculService — le réutiliser directement évite de dupliquer
 *     cette logique et corrige, au passage, le fait que les PaieLigne
 *     n'étaient générées qu'à l'ouverture de SalaireController::index()
 *     (cf. PaieCalculService::getOrGenererPeriode()).
 *
 * Chaque montant utilisé est le RESTE à financer (montant ajusté déjà
 * appliqué, déjà payé déduit) — jamais le brut théorique. Les commissions
 * annulées ou entièrement payées ne contribuent jamais (cf.
 * PeriodeCalculatorService, qui exclut désormais les parts annulées, et
 * montant_restant/reste_a_payer qui valent 0 pour une part déjà payée).
 *
 * ⚠️ Limite connue et documentée : PaiementFichePaiement (le paiement d'une
 * fiche livreur/propriétaire) ne met pas à jour montant_verse sur la
 * CommissionPart/CommissionLogistiquePart source — seul le montant payé au
 * niveau de la fiche est fiable. Si un opérateur enregistre un paiement via
 * les anciens écrans par bénéficiaire (commissions/vente/livreurs/...) au
 * lieu des Fiches, ce paiement ne sera pas reflété ici. Voir le compte rendu
 * livré avec cette fonctionnalité pour le détail du risque.
 */
class BesoinTresorerieService
{
    private const SANS_AGENCE = '__sans_agence__';

    public function __construct(
        private readonly PeriodePaiementService $periodePaiementService,
        private readonly PeriodeCalculatorService $periodeCalculatorService,
        private readonly PaieCalculService $paieCalculService,
    ) {}

    /**
     * @return list<array{site_id: ?string, site_nom: string, livreurs_p1: float, livreurs_p2: float, proprietaires: float, salaires: float, total: float}>
     */
    public function calculerPourMois(string $organizationId, int $annee, int $mois): array
    {
        $besoin = [];

        $this->accumulerLivreurs($organizationId, $annee, $mois, $besoin);
        $this->accumulerProprietaires($organizationId, $annee, $mois, $besoin);
        $this->accumulerSalaires($organizationId, $annee, $mois, $besoin);

        return $this->formatResultat($organizationId, $besoin);
    }

    /**
     * Somme toutes agences confondues — sert de ligne "Total général" à l'écran.
     *
     * @param  list<array{livreurs_p1: float, livreurs_p2: float, proprietaires: float, salaires: float, total: float}>  $rows
     * @return array{livreurs_p1: float, livreurs_p2: float, proprietaires: float, salaires: float, total: float}
     */
    public static function totalGeneral(array $rows): array
    {
        return [
            'livreurs_p1' => round((float) array_sum(array_column($rows, 'livreurs_p1')), 2),
            'livreurs_p2' => round((float) array_sum(array_column($rows, 'livreurs_p2')), 2),
            'proprietaires' => round((float) array_sum(array_column($rows, 'proprietaires')), 2),
            'salaires' => round((float) array_sum(array_column($rows, 'salaires')), 2),
            'total' => round((float) array_sum(array_column($rows, 'total')), 2),
        ];
    }

    /**
     * Détail des bénéficiaires composant le besoin d'une agence pour un mois
     * (drill-down) — $siteId à null pour le regroupement "Sans agence".
     *
     * @return array{
     *   livreurs_p1: list<array>,
     *   livreurs_p2: list<array>,
     *   proprietaires: list<array>,
     *   salaires: list<array>,
     * }
     */
    public function detailAgence(string $organizationId, int $annee, int $mois, ?string $siteId): array
    {
        return [
            'livreurs_p1' => $this->detailFichesPourQuinzaine($organizationId, $annee, $mois, PeriodePaiementService::P1, TypePeriodePaiement::LIVREUR, $siteId),
            'livreurs_p2' => $this->detailFichesPourQuinzaine($organizationId, $annee, $mois, PeriodePaiementService::P2, TypePeriodePaiement::LIVREUR, $siteId),
            'proprietaires' => [
                ...$this->detailFichesPourQuinzaine($organizationId, $annee, $mois, PeriodePaiementService::P1, TypePeriodePaiement::PROPRIETAIRE, $siteId),
                ...$this->detailFichesPourQuinzaine($organizationId, $annee, $mois, PeriodePaiementService::P2, TypePeriodePaiement::PROPRIETAIRE, $siteId),
            ],
            'salaires' => $this->detailSalaires($organizationId, $annee, $mois, $siteId),
        ];
    }

    // ── Accumulation par poste ───────────────────────────────────────────────────

    /** @param  array<string, array<string, float>>  $besoin */
    private function accumulerLivreurs(string $orgId, int $annee, int $mois, array &$besoin): void
    {
        $colonnes = [
            PeriodePaiementService::P1 => 'livreurs_p1',
            PeriodePaiementService::P2 => 'livreurs_p2',
        ];

        foreach ($colonnes as $quinzaine => $colonne) {
            $periode = $this->calculerPeriodeQuinzaine($orgId, $annee, $mois, $quinzaine, TypePeriodePaiement::LIVREUR);

            PaiementFiche::where('organization_id', $orgId)
                ->where('periode_id', $periode->id)
                ->where('beneficiaire_type', 'livreur')
                ->get(['site_id', 'montant_net', 'montant_paye'])
                ->each(function (PaiementFiche $fiche) use (&$besoin, $colonne) {
                    $cle = $fiche->site_id ?? self::SANS_AGENCE;
                    $besoin[$cle][$colonne] = ($besoin[$cle][$colonne] ?? 0.0) + $fiche->montant_restant;
                });
        }
    }

    /** @param  array<string, array<string, float>>  $besoin */
    private function accumulerProprietaires(string $orgId, int $annee, int $mois, array &$besoin): void
    {
        // Contrairement aux livreurs, une seule colonne "Propriétaires" par mois :
        // on cumule les deux quinzaines (P1 + P2), sans double comptage puisque
        // chacune couvre une plage de dates disjointe.
        foreach ([PeriodePaiementService::P1, PeriodePaiementService::P2] as $quinzaine) {
            $periode = $this->calculerPeriodeQuinzaine($orgId, $annee, $mois, $quinzaine, TypePeriodePaiement::PROPRIETAIRE);

            PaiementFiche::where('organization_id', $orgId)
                ->where('periode_id', $periode->id)
                ->where('beneficiaire_type', 'proprietaire')
                ->get(['site_id', 'montant_net', 'montant_paye'])
                ->each(function (PaiementFiche $fiche) use (&$besoin) {
                    $cle = $fiche->site_id ?? self::SANS_AGENCE;
                    $besoin[$cle]['proprietaires'] = ($besoin[$cle]['proprietaires'] ?? 0.0) + $fiche->montant_restant;
                });
        }
    }

    /** @param  array<string, array<string, float>>  $besoin */
    private function accumulerSalaires(string $orgId, int $annee, int $mois, array &$besoin): void
    {
        $periode = $this->paieCalculService->getOrGenererPeriode($orgId, $mois, $annee);

        PaieLigne::where('paie_periode_id', $periode->id)
            ->with('employe:id,site_id')
            ->get(['id', 'employe_id', 'reste_a_payer'])
            ->each(function (PaieLigne $ligne) use (&$besoin) {
                $cle = $ligne->employe?->site_id ?? self::SANS_AGENCE;
                $besoin[$cle]['salaires'] = ($besoin[$cle]['salaires'] ?? 0.0) + (float) $ligne->reste_a_payer;
            });
    }

    // ── Détail (drill-down) ──────────────────────────────────────────────────────

    /** @return list<array{id: string, nom: string, montant_net: float, montant_paye: float, montant_restant: float, statut: ?string, statut_label: string}> */
    private function detailFichesPourQuinzaine(string $orgId, int $annee, int $mois, string $quinzaine, TypePeriodePaiement $type, ?string $siteId): array
    {
        $periode = $this->calculerPeriodeQuinzaine($orgId, $annee, $mois, $quinzaine, $type);
        $beneficiaireType = $type === TypePeriodePaiement::LIVREUR ? 'livreur' : 'proprietaire';

        return PaiementFiche::where('organization_id', $orgId)
            ->where('periode_id', $periode->id)
            ->where('beneficiaire_type', $beneficiaireType)
            ->where('site_id', $siteId)
            ->get()
            ->filter(fn (PaiementFiche $f) => $f->montant_restant > 0.0)
            ->map(fn (PaiementFiche $f) => [
                'id' => $f->id,
                'nom' => $f->beneficiaire_nom,
                'montant_net' => (float) $f->montant_net,
                'montant_paye' => (float) $f->montant_paye,
                'montant_restant' => $f->montant_restant,
                'statut' => $f->statut?->value,
                'statut_label' => $f->statut_label,
            ])
            ->values()
            ->all();
    }

    /** @return list<array{id: string, nom: ?string, net: float, deja_paye: float, reste_a_payer: float, statut: ?string}> */
    private function detailSalaires(string $orgId, int $annee, int $mois, ?string $siteId): array
    {
        $periode = $this->paieCalculService->getOrGenererPeriode($orgId, $mois, $annee);

        return PaieLigne::where('paie_periode_id', $periode->id)
            ->whereHas('employe', fn ($q) => $q->where('site_id', $siteId))
            ->with('employe:id,nom_complet')
            ->get()
            ->filter(fn (PaieLigne $l) => (float) $l->reste_a_payer > 0.0)
            ->map(fn (PaieLigne $l) => [
                'id' => $l->id,
                'nom' => $l->employe?->nom_complet,
                'net' => (float) $l->net,
                'deja_paye' => (float) $l->deja_paye,
                'reste_a_payer' => (float) $l->reste_a_payer,
                'statut' => $l->statut?->value,
            ])
            ->values()
            ->all();
    }

    // ── Utilitaires ───────────────────────────────────────────────────────────────

    private function calculerPeriodeQuinzaine(string $orgId, int $annee, int $mois, string $quinzaine, TypePeriodePaiement $type): PaiementPeriode
    {
        [$debut] = PeriodePaiementService::dateRangeFor($annee, $mois, $quinzaine);
        $periode = $this->periodePaiementService->getOrCreatePeriod($orgId, $type, $debut);
        $this->periodeCalculatorService->calculerSiNecessaire($periode);

        return $periode;
    }

    /**
     * @param  array<string, array<string, float>>  $besoin
     * @return list<array{site_id: ?string, site_nom: string, livreurs_p1: float, livreurs_p2: float, proprietaires: float, salaires: float, total: float}>
     */
    private function formatResultat(string $orgId, array $besoin): array
    {
        $rows = Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom'])
            ->map(fn (Site $site) => $this->buildRow($site->id, $site->nom, $besoin[$site->id] ?? []))
            ->all();

        if (isset($besoin[self::SANS_AGENCE])) {
            $sansAgence = $this->buildRow(null, 'Sans agence', $besoin[self::SANS_AGENCE]);
            if ($sansAgence['total'] > 0.0) {
                $rows[] = $sansAgence;
            }
        }

        return $rows;
    }

    /** @param  array<string, float>  $montants */
    private function buildRow(?string $siteId, string $siteNom, array $montants): array
    {
        $livreursP1 = round($montants['livreurs_p1'] ?? 0.0, 2);
        $livreursP2 = round($montants['livreurs_p2'] ?? 0.0, 2);
        $proprietaires = round($montants['proprietaires'] ?? 0.0, 2);
        $salaires = round($montants['salaires'] ?? 0.0, 2);

        return [
            'site_id' => $siteId,
            'site_nom' => $siteNom,
            'livreurs_p1' => $livreursP1,
            'livreurs_p2' => $livreursP2,
            'proprietaires' => $proprietaires,
            'salaires' => $salaires,
            'total' => round($livreursP1 + $livreursP2 + $proprietaires + $salaires, 2),
        ];
    }
}
