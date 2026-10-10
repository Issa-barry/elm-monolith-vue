<?php

namespace App\Http\Controllers\Achats;

use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Services\Achats\CommandeAchatService;
use App\Services\Achats\PerimetreCommandesAchat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AnnulerCommandeAchatController extends Controller
{
    public function __invoke(Request $request, CommandeAchat $achat, CommandeAchatService $service): RedirectResponse
    {
        $this->authorize('annuler', $achat);
        app(PerimetreCommandesAchat::class)->autoriserAction($achat, auth()->user());

        $data = $request->validate([
            'motif_annulation' => 'required|string|max:2000',
        ], [
            'motif_annulation.required' => "Le motif d'annulation est obligatoire.",
        ]);

        $service->annuler($achat, $request->user(), $data['motif_annulation']);

        return back()->with('success', 'Commande annulée.');
    }
}
