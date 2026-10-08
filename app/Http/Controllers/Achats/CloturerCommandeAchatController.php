<?php

namespace App\Http\Controllers\Achats;

use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Services\Achats\CommandeAchatService;
use App\Services\Achats\PerimetreCommandesAchat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CloturerCommandeAchatController extends Controller
{
    public function __invoke(Request $request, CommandeAchat $achat, CommandeAchatService $service): RedirectResponse
    {
        $this->authorize('annuler', $achat);
        app(PerimetreCommandesAchat::class)->autoriser($achat, auth()->user());

        $data = $request->validate([
            'motif_cloture' => 'required|string|max:2000',
        ], [
            'motif_cloture.required' => 'Le motif de clôture est obligatoire.',
        ]);

        $service->cloturerReliquat($achat, $request->user(), $data['motif_cloture']);

        return back()->with('success', 'Commande clôturée : le reliquat ne sera plus attendu.');
    }
}
