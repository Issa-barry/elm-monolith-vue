<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Models\CashbackTransaction;
use App\Models\Client;
use App\Services\CashbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CashbackController extends Controller
{
    public function __construct(private readonly CashbackService $cashback) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CashbackTransaction::class);

        $orgId = auth()->user()->organization_id;

        $query = CashbackTransaction::with(['client', 'versements'])
            ->where('cashback_transactions.organization_id', $orgId)
            ->where('type', CashbackTransaction::TYPE_GAIN);

        if ($request->filled('statut') && in_array($request->statut, ['en_attente', 'valide', 'partiel', 'verse'], true)) {
            $query->where('statut', $request->statut);
        }

        if ($request->filled('client_id') && is_numeric($request->client_id)) {
            $query->where('client_id', (int) $request->client_id);
        }

        $transactions = $query
            ->orderByRaw("CASE WHEN statut = 'en_attente' THEN 0 WHEN statut = 'valide' THEN 1 WHEN statut = 'partiel' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->get()
            ->map(function (CashbackTransaction $t) {
                // Source de vérité : on recalcule depuis la relation eager-loadée.
                // Protège contre les données héritées (montant_verse=0 sur un statut 'verse').
                $verseReel = (int) $t->versements->sum('montant');
                $restantReel = max(0, $t->montant - $verseReel);

                return [
                    'id' => $t->id,
                    'client' => $t->client ? [
                        'id' => $t->client->id,
                        'nom_complet' => $t->client->nom_complet,
                        'telephone' => $t->client->telephone,
                    ] : null,
                    'montant' => $t->montant,
                    'montant_verse' => $verseReel,
                    'montant_restant' => $restantReel,
                    'statut' => $t->statut,
                    'note' => $t->note,
                    'valide_le' => $t->valide_le?->toDateTimeString(),
                    'verse_le' => $t->verse_le?->toDateTimeString(),
                    'created_at' => $t->created_at->toDateTimeString(),
                    'versements' => $t->versements->map(fn ($v) => [
                        'id' => $v->id,
                        'montant' => $v->montant,
                        'mode_paiement' => $v->mode_paiement,
                        'date_versement' => $v->date_versement?->format('d/m/Y'),
                        'note' => $v->note,
                    ]),
                ];
            });

        $user = auth()->user();

        $clients = Client::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'prenom']);

        return Inertia::render('Cashback/Index', [
            'transactions' => $transactions,
            'can_valider' => $user->hasAnyRole(['super_admin', 'admin_entreprise']),
            'modes_paiement' => ModePaiement::options(),
            'clients' => $clients->map(fn ($c) => ['id' => $c->id, 'nom_complet' => $c->nom_complet]),
            'filters' => $request->only(['statut', 'client_id']),
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

        $restant = $cashbackTransaction->montant_restant;

        $validated = $request->validate([
            'montant' => ['required', 'integer', 'min:1', "max:{$restant}"],
            'mode_paiement' => ['required', Rule::in(array_column(ModePaiement::cases(), 'value'))],
            'date_versement' => 'required|date',
            'note' => 'nullable|string|max:500',
        ], [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
            'montant.max' => "Le montant dépasse le restant dû ({$restant} GNF).",
            'mode_paiement.required' => 'Le mode de paiement est obligatoire.',
            'date_versement.required' => 'La date de versement est obligatoire.',
        ]);

        $this->cashback->verser(
            $cashbackTransaction,
            auth()->user(),
            (int) $validated['montant'],
            $validated['mode_paiement'],
            $validated['date_versement'],
            $validated['note'] ?? null
        );

        return back()->with('success', 'Versement enregistré.');
    }
}
