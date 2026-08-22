<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Http\Controllers\Controller;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppePart;
use App\Models\Depense;
use App\Models\Organization;
use App\Models\Prestataire;
use App\Services\CommissionVenteCalculatorService;
use App\Services\PeriodeComptableService;
use App\Support\Commission\CommissionKpiBuckets;
use App\Support\PhoneFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Écran "Commission consultants" — mirroring CommissionSiteController, source
 * CommissionEnveloppePart filtrée sur beneficiaire_type=prestataire ET enveloppe.cible_type=
 * consultant (jamais un simple beneficiaire_type=prestataire seul, cf. convention déjà établie
 * pour site/proprietaire — un prestataire pourrait un jour être bénéficiaire d'une autre cible).
 * Le bénéficiaire EST le prestataire désigné au moment de chaque commission (snapshotté dans
 * CommissionEnveloppePart, jamais recalculé) — jamais Fello Consulting ni aucun prestataire codé
 * en dur. Contrairement à Commission sites, aucune notion d'agence : le consultant est désigné au
 * niveau de l'organisation (cf. CommissionConsultantAffectation), pas d'un site — aucun filtre
 * Agence/site_ids ici. L'écran affiche TOUS les prestataires ayant des parts historiques, y
 * compris un ancien consultant remplacé ou désactivé depuis (cf. mission §3) : le groupBy sur
 * beneficiaire_id — jamais un filtre sur "le consultant actuel" — garantit cette persistance.
 * Mêmes conventions que les autres écrans Commission v2 : cartes de synthèse, DataFilters,
 * StatusDot, exports, jamais de paiement direct (chaîne unique via Comptabilité > Fiches de
 * paiement).
 */
class CommissionConsultantController extends Controller
{
    private function scalarInput(Request $request, string $key): string
    {
        $value = $request->input($key, '');

        return trim(is_array($value) ? (string) reset($value) : (string) $value);
    }

    public function index(Request $request): Response
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);

        [$list, $meta] = $this->resolveBeneficiaires($request);
        $orgId = auth()->user()->organization_id;

        $kpis = [
            'commissions_generees' => (float) $list->sum('total_genere'),
            'depenses' => (float) $list->sum('total_frais'),
            'net_valide' => (float) $list->sum('total_net_cumule'),
            'reste_a_payer' => (float) $list->sum('solde_restant'),
        ];

        $earliestDate = CommissionEnveloppePart::where('beneficiaire_type', CommissionEnveloppePart::TYPE_PRESTATAIRE)
            ->whereHas('enveloppe', fn ($q) => $q->where('organization_id', $orgId)->where('cible_type', CommissionCibleType::CODE_CONSULTANT))
            ->join('commission_enveloppes', 'commission_enveloppes.id', '=', 'commission_enveloppe_parts.enveloppe_id')
            ->min('commission_enveloppes.earned_at');

        $periodesDisponibles = $earliestDate
            ? PeriodeComptableService::periodesDisponibles(Carbon::parse($earliestDate))
            : [];

        return Inertia::render('Comptabilite/CommissionConsultant/Index', [
            'beneficiaires' => $list,
            'kpis' => $kpis,
            'search' => $meta['search'],
            'filtre_statut' => $meta['filtre_statut'],
            'filtre_consultant_id' => $meta['filtre_consultant_id'],
            'selected_periode' => $meta['filtre_periode'],
            'periodes_disponibles' => $periodesDisponibles,
            'consultants_options' => $meta['consultants_options'],
            'can_payer' => false,
        ]);
    }

    /**
     * Construit la liste des bénéficiaires (= prestataires ayant des parts "consultant")
     * filtrée — unique source pour index() ET les exports, jamais deux implémentations
     * divergentes du même périmètre visible.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function resolveBeneficiaires(Request $request): array
    {
        $orgId = auth()->user()->organization_id;
        $search = trim((string) $request->input('search', ''));
        $filtreStatut = $this->scalarInput($request, 'statut');
        $filtrePeriode = $this->scalarInput($request, 'periode');
        if ($filtrePeriode !== '' && ! preg_match('/^\d{4}-\d{2}-(P1|P2|M)$/', $filtrePeriode)) {
            $filtrePeriode = '';
        }
        $filtreConsultantId = $this->scalarInput($request, 'consultant_id');

        $query = CommissionEnveloppePart::with(['enveloppe.source'])
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_PRESTATAIRE)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->whereHas('enveloppe', function ($q) use ($orgId, $filtrePeriode) {
                $q->where('organization_id', $orgId)
                    ->where('cible_type', CommissionCibleType::CODE_CONSULTANT);
                if ($filtrePeriode !== '') {
                    [$debut, $fin] = PeriodeComptableService::dateRangeForCode($filtrePeriode);
                    $q->whereBetween('earned_at', [$debut, $fin]);
                }
            });

        if ($filtreConsultantId !== '') {
            $query->where('beneficiaire_id', $filtreConsultantId);
        }

        $allParts = $query->get();
        $partsParConsultant = $allParts->groupBy('beneficiaire_id');

        $allConsultantIds = $partsParConsultant->keys()->map(fn ($id) => (string) $id)->all();
        $fraisDepensesParConsultant = $this->fraisDepensesParConsultant($orgId, $allConsultantIds, $filtrePeriode);
        $prestatairesById = Prestataire::with(['personne', 'entrepriseTierce'])
            ->whereIn('id', $allConsultantIds)
            ->get()
            ->keyBy('id');

        $beneficiaires = $partsParConsultant->map(function (Collection $parts, string $consultantId) use (
            $fraisDepensesParConsultant, $prestatairesById,
        ) {
            $prestataire = $prestatairesById->get($consultantId);
            $fraisDepenses = $fraisDepensesParConsultant[$consultantId] ?? 0.0;

            $partsPayables = $parts->filter(
                fn (CommissionEnveloppePart $p) => $p->statut !== StatutCommission::CREEE
            );

            $resume = CommissionVenteCalculatorService::calculerResume(
                (float) $partsPayables->sum('montant_brut'),
                0.0,
                (float) $partsPayables->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer),
                $fraisDepenses,
                (float) $partsPayables->sum('montant_verse'),
            );

            $buckets = CommissionKpiBuckets::calculer($parts);

            $statutGlobal = $partsPayables->isEmpty() && $buckets['en_attente_periode'] > 0.009
                ? StatutCommission::CREEE->value
                : $resume['statut'];

            return [
                'beneficiaire_id' => $consultantId,
                'beneficiaire_nom' => $prestataire?->nom_complet ?? '—',
                'reference' => $prestataire?->reference,
                'telephone' => $prestataire?->phone,
                'total_brut_cumule' => $resume['brut'],
                'total_frais' => $resume['frais'],
                'total_net_cumule' => $resume['net'],
                'total_verse' => $resume['verse'],
                'solde_restant' => $resume['reste'],
                'nb_commandes' => $parts->pluck('enveloppe_id')->unique()->count(),
                'statut_global' => $statutGlobal,
                'statut_label' => $this->statutLabel($statutGlobal),
                'total_genere' => $buckets['total_genere'],
                'en_attente_periode' => $buckets['en_attente_periode'],
                'payable' => $buckets['payable'],
                // Jamais payable depuis cet écran, cf. docblock de classe.
                'can_pay' => false,
            ];
        })->values();

        if ($filtreStatut !== '') {
            $beneficiaires = $beneficiaires->filter(fn ($b) => $b['statut_global'] === $filtreStatut);
        }

        if ($search !== '') {
            $s = mb_strtolower($search);
            $beneficiaires = $beneficiaires->filter(
                fn ($b) => str_contains(mb_strtolower((string) $b['beneficiaire_nom']), $s)
                    || str_contains(mb_strtolower((string) $b['reference']), $s)
            );
        }

        // Options de filtre "Consultant" : TOUS les prestataires ayant au moins une part
        // historique dans ce périmètre (avant filtre statut/recherche), pas seulement le
        // consultant désigné actuellement — cf. mission §3.
        $consultantsOptions = $prestatairesById
            ->map(fn (Prestataire $p) => ['value' => $p->id, 'label' => $p->nom_complet ?? $p->reference])
            ->sortBy('label')
            ->values();

        return [$beneficiaires->sortBy('beneficiaire_nom')->values(), [
            'search' => $search,
            'filtre_statut' => $filtreStatut,
            'filtre_periode' => $filtrePeriode,
            'filtre_consultant_id' => $filtreConsultantId,
            'consultants_options' => $consultantsOptions,
        ]];
    }

    private function statutLabel(string $statut): string
    {
        return match ($statut) {
            StatutCommission::CREEE->value => 'Créée',
            StatutCommission::IMPAYE->value => 'Impayé',
            StatutCommission::PARTIEL->value => 'Partiel',
            StatutCommission::PAYE->value => 'Payé',
            StatutCommission::ANNULEE->value => 'Annulée',
            default => $statut,
        };
    }

    /**
     * Dépenses attribuées directement au prestataire (beneficiaire_type=prestataire) —
     * mécanisme désormais pleinement fonctionnel (CategorieDepense::PRESTATAIRE, cf.
     * DepenseController), contrairement au même point resté un no-op documenté côté Commission
     * sites : une dépense créée avec ce bénéficiaire est réellement déduite ici.
     *
     * @param  array<int, string>  $consultantIds
     */
    private function fraisDepensesParConsultant(string $orgId, array $consultantIds, string $periode = ''): array
    {
        if (empty($consultantIds)) {
            return [];
        }

        $query = Depense::where('beneficiaire_type', CommissionEnveloppePart::TYPE_PRESTATAIRE)
            ->whereIn('beneficiaire_id', $consultantIds)
            ->where('statut', StatutDepense::VALIDE->value)
            ->where('organization_id', $orgId);

        if ($periode !== '') {
            [$debut, $fin] = PeriodeComptableService::dateRangeForCode($periode);
            $query->whereBetween('date_depense', [$debut->toDateString(), $fin->toDateString().' 23:59:59']);
        }

        return $query->get(['beneficiaire_id', 'montant'])
            ->groupBy('beneficiaire_id')
            ->map(fn ($d) => (float) $d->sum('montant'))
            ->toArray();
    }

    // ── Exports ───────────────────────────────────────────────────────────────
    // Reprennent exactement le périmètre visible dans index() (mêmes filtres) — seule
    // la sortie diffère.

    public function exportExcel(Request $request): StreamedResponse
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);
        abort_unless(auth()->user()->can('commissions.exporter'), 403);

        [$rows] = $this->resolveBeneficiaires($request);
        $filename = 'commissions-consultants-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Consultant', 'Référence', 'Téléphone', 'Généré (GNF)', 'Brut validé (GNF)', 'Dépenses (GNF)', 'Net validé (GNF)', 'Déjà payé (GNF)', 'Reste à payer (GNF)', 'Statut'], ';');
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['beneficiaire_nom'],
                    $row['reference'] ?? '',
                    PhoneFormatter::display($row['telephone'] ?? null),
                    number_format((float) $row['total_genere'], 0, ',', ' '),
                    number_format((float) $row['total_brut_cumule'], 0, ',', ' '),
                    number_format((float) $row['total_frais'], 0, ',', ' '),
                    number_format((float) $row['total_net_cumule'], 0, ',', ' '),
                    number_format((float) $row['total_verse'], 0, ',', ' '),
                    number_format((float) $row['solde_restant'], 0, ',', ' '),
                    $row['statut_label'],
                ], ';');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportPdf(Request $request): HttpResponse
    {
        abort_unless(auth()->user()->can('comptabilite.read'), 403);
        abort_unless(auth()->user()->can('commissions.exporter'), 403);

        [$rows] = $this->resolveBeneficiaires($request);
        $org = Organization::find(auth()->user()->organization_id);
        $filtrePeriode = $this->scalarInput($request, 'periode');
        $periodeLabel = $filtrePeriode !== '' ? PeriodeComptableService::labelForCode($filtrePeriode) : 'Toutes périodes';

        $pdf = Pdf::loadView('pdf.commissions.consultants', [
            'title' => 'Commissions des consultants',
            'org' => $org,
            'periode_label' => $periodeLabel,
            'rows' => $rows,
            'printed_by' => auth()->user()->name ?? '—',
            'generated_at' => now(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('commissions-consultants-'.now()->format('Y-m-d').'.pdf');
    }
}
