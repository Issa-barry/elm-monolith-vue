<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Enums\StatutDepense;
use App\Models\CashbackTransaction;
use App\Models\Client;
use App\Models\Depense;
use App\Services\CashbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CashbackController extends Controller
{
    private const STATUTS = ['en_attente', 'valide', 'partiel', 'verse'];

    public function __construct(private readonly CashbackService $cashback) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CashbackTransaction::class);

        $orgId = auth()->user()->organization_id;
        $statut = $this->scalarInput($request, 'statut');
        $clientId = $this->scalarInput($request, 'client_id');
        $dateDebut = trim((string) $request->input('date_debut', ''));
        $dateFin = trim((string) $request->input('date_fin', ''));

        $query = CashbackTransaction::with(['client', 'versements'])
            ->where('cashback_transactions.organization_id', $orgId)
            ->gains();

        if (in_array($statut, self::STATUTS, true)) {
            $query->where('statut', $statut);
        } else {
            $statut = '';
        }

        if ($clientId !== '') {
            $query->where('client_id', $clientId);
        }
        if ($dateDebut !== '') {
            $query->whereDate('created_at', '>=', $dateDebut);
        }
        if ($dateFin !== '') {
            $query->whereDate('created_at', '<=', $dateFin);
        }

        $transactions = $query->orderByDesc('created_at')->get();
        $clientIds = $transactions->pluck('client_id')->unique()->values();
        $depensesParClient = $this->depensesValidees($orgId, $clientIds, $dateDebut, $dateFin)
            ->groupBy('beneficiaire_id');

        $clients = $transactions
            ->groupBy('client_id')
            ->map(fn (Collection $items, string $id) => $this->resumeClient(
                $items,
                $depensesParClient->get($id, collect()),
            ))
            ->sortBy('client_nom')
            ->values();

        $optionsClients = Client::where('organization_id', $orgId)
            ->whereHas('cashbackTransactions', fn ($q) => $q->gains())
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get(['id', 'nom', 'prenom', 'nom_complet'])
            ->map(fn (Client $client) => ['id' => $client->id, 'nom_complet' => $client->nom_complet])
            ->values();

        return Inertia::render('Comptabilite/Cashback/Index', [
            'beneficiaires' => $clients,
            'kpis' => [
                'nb_clients' => $clients->count(),
                'total_genere' => (float) $clients->sum('total_genere'),
                'total_frais' => (float) $clients->sum('total_frais'),
                'total_net' => (float) $clients->sum('total_net'),
                'total_verse' => (float) $clients->sum('total_verse'),
                'solde_total' => (float) $clients->sum('solde_restant'),
            ],
            'clients' => $optionsClients,
            'filters' => [
                'client_id' => $clientId,
                'statut' => $statut,
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
            ],
        ]);
    }

    public function show(Request $request, Client $client): Response
    {
        $this->authorize('viewAny', CashbackTransaction::class);
        abort_if($client->organization_id !== auth()->user()->organization_id, 403);

        $transactions = CashbackTransaction::with(['vente', 'versements.creator'])
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->id)
            ->gains()
            ->orderByDesc('created_at')
            ->get();

        $depenses = Depense::with(['depenseType:id,libelle', 'user', 'validateur'])
            ->where('organization_id', $client->organization_id)
            ->where('beneficiaire_type', 'client')
            ->where('beneficiaire_id', $client->id)
            ->where('statut', StatutDepense::VALIDE->value)
            ->orderByDesc('date_depense')
            ->get();

        $resume = $this->resumeClient($transactions, $depenses);

        return Inertia::render('Comptabilite/Cashback/Show', [
            'client' => [
                'id' => $client->id,
                'nom' => $client->nom_complet,
                'telephone' => $client->telephone,
            ],
            'commission_summary' => [
                'brut_cumule' => $resume['total_valide'],
                'frais' => $resume['total_frais'],
                'net_a_payer' => $resume['total_net'],
                'deja_paye' => $resume['total_verse'],
                'reste_a_payer' => $resume['solde_restant'],
                'total_genere' => $resume['total_genere'],
                'en_attente_periode' => $resume['en_attente_validation'],
                'payable' => $resume['total_net'],
            ],
            'transactions' => $transactions->map(fn (CashbackTransaction $transaction) => $this->transformTransaction($transaction)),
            'expenses' => $depenses->map(fn (Depense $depense) => [
                'id' => $depense->id,
                'date' => $depense->date_depense?->format('d/m/Y'),
                'type' => $depense->depenseType?->libelle ?? '—',
                'commentaire' => $depense->commentaire,
                'saisi_par' => $depense->user?->name,
                'validateur' => $depense->validateur?->name,
                'vehicule' => null,
                'montant' => (float) $depense->montant,
            ])->values(),
            'payments' => $transactions->flatMap(fn (CashbackTransaction $transaction) => $transaction->versements->map(fn ($versement) => [
                'id' => $versement->id,
                'paid_at' => $versement->date_versement?->format('d/m/Y'),
                'montant' => (float) $versement->montant,
                'mode_paiement' => $versement->mode_paiement,
                'note' => $versement->note,
                'created_by' => $versement->creator?->name,
            ]))->sortByDesc('paid_at')->values(),
            'can_valider' => auth()->user()->hasAnyRole(['super_admin', 'admin_entreprise']),
            'modes_paiement' => ModePaiement::options(),
            'montant_disponible' => $this->cashback->montantDisponibleClient($client->organization_id, $client->id),
        ]);
    }

    public function valider(Request $request, CashbackTransaction $cashbackTransaction): RedirectResponse
    {
        $this->authorize('valider', $cashbackTransaction);
        abort_if($cashbackTransaction->organization_id !== auth()->user()->organization_id, 403);
        abort_if(! $cashbackTransaction->isEnAttente(), 422, 'Cette transaction ne peut pas être validée.');

        $validated = $request->validate(['note' => 'nullable|string|max:500']);
        $this->cashback->valider($cashbackTransaction, auth()->user(), $validated['note'] ?? null);

        return back()->with('success', 'Cashback validé. Il peut maintenant être versé.');
    }

    public function verser(Request $request, CashbackTransaction $cashbackTransaction): RedirectResponse
    {
        $this->authorize('update', $cashbackTransaction);
        abort_if($cashbackTransaction->organization_id !== auth()->user()->organization_id, 403);
        abort_if(! $cashbackTransaction->isVersable(), 422, 'Ce cashback doit être validé avant le versement.');

        $maximum = min(
            $cashbackTransaction->montant_restant,
            $this->cashback->montantDisponibleClient($cashbackTransaction->organization_id, $cashbackTransaction->client_id),
        );

        $validated = $request->validate([
            'montant' => ['required', 'integer', 'min:1', "max:{$maximum}"],
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'date_versement' => 'required|date',
            'note' => 'nullable|string|max:500',
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
            'montant.max' => "Le montant dépasse le cashback net disponible ({$maximum} GNF).",
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
            'date_versement.required' => 'La date du versement est obligatoire.',
        ]);

        try {
            $this->cashback->verser(
                $cashbackTransaction,
                auth()->user(),
                (int) $validated['montant'],
                $validated['mode_paiement'],
                $validated['date_versement'],
                $validated['note'] ?? null,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['comptabilisation' => "Versement non enregistré : {$e->getMessage()}"]);
        }

        return back()->with('success', 'Versement enregistré.');
    }

    private function scalarInput(Request $request, string $key): string
    {
        $value = $request->input($key, '');

        return trim(is_array($value) ? (string) reset($value) : (string) $value);
    }

    private function depensesValidees(string $orgId, Collection $clientIds, string $dateDebut, string $dateFin): Collection
    {
        if ($clientIds->isEmpty()) {
            return collect();
        }

        $query = Depense::where('organization_id', $orgId)
            ->where('beneficiaire_type', 'client')
            ->whereIn('beneficiaire_id', $clientIds)
            ->where('statut', StatutDepense::VALIDE->value);

        if ($dateDebut !== '') {
            $query->whereDate('date_depense', '>=', $dateDebut);
        }
        if ($dateFin !== '') {
            $query->whereDate('date_depense', '<=', $dateFin);
        }

        return $query->get(['beneficiaire_id', 'montant']);
    }

    private function resumeClient(Collection $transactions, Collection $depenses): array
    {
        /** @var CashbackTransaction $premiere */
        $premiere = $transactions->first();
        $totalGenere = (int) $transactions->sum('montant');
        $totalValide = (int) $transactions
            ->whereIn('statut', ['valide', 'partiel', 'verse'])
            ->sum('montant');
        $totalVerse = (int) $transactions->sum(fn (CashbackTransaction $transaction) => $transaction->versements->sum('montant'));
        $totalFrais = (int) $depenses->sum('montant');
        $totalNet = max(0, $totalValide - $totalFrais);
        $solde = max(0, $totalNet - $totalVerse);
        $enAttente = max(0, $totalGenere - $totalValide);

        $statut = match (true) {
            $enAttente > 0 => 'en_attente',
            $solde <= 0 && $totalValide > 0 => 'verse',
            $totalVerse > 0 => 'partiel',
            default => 'valide',
        };

        return [
            'client_id' => $premiere->client_id,
            'client_nom' => $premiere->client?->nom_complet ?? '—',
            'telephone' => $premiere->client?->telephone,
            'nb_transactions' => $transactions->count(),
            'total_genere' => $totalGenere,
            'en_attente_validation' => $enAttente,
            'total_valide' => $totalValide,
            'total_frais' => $totalFrais,
            'total_net' => $totalNet,
            'total_verse' => $totalVerse,
            'solde_restant' => $solde,
            'statut' => $statut,
            'statut_label' => $this->statutLabel($statut),
        ];
    }

    private function transformTransaction(CashbackTransaction $transaction): array
    {
        $verse = (int) $transaction->versements->sum('montant');

        return [
            'id' => $transaction->id,
            'reference' => $transaction->vente?->reference,
            'date' => $transaction->created_at?->format('d/m/Y'),
            'montant' => (int) $transaction->montant,
            'montant_verse' => $verse,
            'montant_restant' => max(0, (int) $transaction->montant - $verse),
            'statut' => $transaction->statut,
            'statut_label' => $this->statutLabel($transaction->statut),
            'note' => $transaction->note,
            'valide_le' => $transaction->valide_le?->format('d/m/Y H:i'),
        ];
    }

    private function statutLabel(string $statut): string
    {
        return match ($statut) {
            'en_attente' => 'En attente',
            'valide' => 'Validé',
            'partiel' => 'Partiel',
            'verse' => 'Versé',
            default => $statut,
        };
    }
}
