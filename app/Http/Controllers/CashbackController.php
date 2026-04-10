<?php

namespace App\Http\Controllers;

use App\Models\CashbackTransaction;
use App\Models\Client;
use App\Services\CashbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashbackController extends Controller
{
    public function __construct(private readonly CashbackService $cashback) {}

    /**
     * Liste des cashbacks (gains en attente + historique des versements).
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CashbackTransaction::class);

        $orgId = auth()->user()->organization_id;

        $query = CashbackTransaction::with(['client'])
            ->where('cashback_transactions.organization_id', $orgId)
            ->where('type', CashbackTransaction::TYPE_GAIN);

        // Filtre par statut
        if ($request->filled('statut') && in_array($request->statut, ['en_attente', 'verse'], true)) {
            $query->where('statut', $request->statut);
        }

        // Filtre par client
        if ($request->filled('client_id') && is_numeric($request->client_id)) {
            $query->where('client_id', (int) $request->client_id);
        }

        // Filtre par date
        if ($request->filled('date_debut')) {
            $query->whereDate('created_at', '>=', $request->date_debut);
        }
        if ($request->filled('date_fin')) {
            $query->whereDate('created_at', '<=', $request->date_fin);
        }

        $transactions = $query
            ->orderByRaw("CASE WHEN statut = 'en_attente' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (CashbackTransaction $t) => [
                'id' => $t->id,
                'client' => $t->client ? [
                    'id' => $t->client->id,
                    'nom_complet' => $t->client->nom_complet,
                    'telephone' => $t->client->telephone,
                ] : null,
                'montant' => $t->montant,
                'statut' => $t->statut,
                'vente_id' => $t->vente_id,
                'note' => $t->note,
                'verse_le' => $t->verse_le?->toDateTimeString(),
                'created_at' => $t->created_at->toDateTimeString(),
            ]);

        // Liste des clients pour le filtre
        $clients = Client::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'prenom']);

        return Inertia::render('Cashback/Index', [
            'transactions' => $transactions,
            'clients' => $clients->map(fn ($c) => [
                'id' => $c->id,
                'nom_complet' => $c->nom_complet,
            ]),
            'filters' => $request->only(['statut', 'client_id', 'date_debut', 'date_fin']),
        ]);
    }

    /**
     * Verse un cashback à un client.
     */
    public function verser(Request $request, CashbackTransaction $cashbackTransaction): RedirectResponse
    {
        $this->authorize('update', $cashbackTransaction);

        $orgId = auth()->user()->organization_id;
        abort_if($cashbackTransaction->organization_id !== $orgId, 403);
        abort_if(! $cashbackTransaction->isEnAttente(), 422, 'Cette transaction a déjà été versée.');

        $validated = $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $this->cashback->verser($cashbackTransaction, auth()->user(), $validated['note'] ?? null);

        return back()->with('success', 'Cashback versé avec succès.');
    }
}
