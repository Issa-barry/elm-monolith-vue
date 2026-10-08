<?php

namespace App\Http\Controllers\Achats;

use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Services\Achats\CommandeAchatService;
use App\Services\Achats\PerimetreCommandesAchat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Permission `achats.valider` (policy) ; plafond du rôle et agence contrôlés par le service, sous
 * verrou, avec un message explicite en cas de refus (ADR 0021).
 */
class ValiderCommandeAchatController extends Controller
{
    public function __invoke(Request $request, CommandeAchat $achat, CommandeAchatService $service): RedirectResponse
    {
        $this->authorize('valider', $achat);
        app(PerimetreCommandesAchat::class)->autoriser($achat, auth()->user());

        $service->valider($achat, $request->user());

        return back()->with('success', 'Bon de commande validé. Il peut maintenant être réceptionné.');
    }
}
