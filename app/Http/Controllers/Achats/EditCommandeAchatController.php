<?php

namespace App\Http\Controllers\Achats;

use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Support\Achats\CommandeAchatFormOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EditCommandeAchatController extends Controller
{
    public function __invoke(Request $request, CommandeAchat $achat, CommandeAchatFormOptions $options): Response|RedirectResponse
    {
        $this->authorize('update', $achat);
        app(PerimetreCommandesAchat::class)->autoriserAction($achat, auth()->user());

        if (! $achat->isAValider()) {
            return redirect()->route('achats.show', $achat)
                ->with('error', 'Une commande validée, réceptionnée ou annulée ne peut plus être modifiée.');
        }

        $achat->load('lignes');

        return Inertia::render('Achats/Form', [
            'commande' => [
                'id' => $achat->id,
                'reference' => $achat->reference,
                'site_id' => $achat->site_id,
                'site_payeur_id' => $achat->sitePayeurId(),
                'fournisseur_id' => $achat->fournisseur_id,
                'note' => $achat->note,
                'lignes' => $achat->lignes
                    ->filter(fn ($l) => $l->variante_id !== null)
                    ->map(fn ($l) => [
                        'variante_id' => $l->variante_id,
                        'qte' => (int) $l->qte,
                        'prix_achat' => (float) $l->prix_achat_snapshot,
                    ])
                    ->values(),
            ],
            ...$options->pour($request->user()),
        ]);
    }
}
