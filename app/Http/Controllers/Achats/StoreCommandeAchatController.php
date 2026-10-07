<?php

namespace App\Http\Controllers\Achats;

use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Services\Achats\CommandeAchatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreCommandeAchatController extends Controller
{
    public function __invoke(Request $request, CommandeAchatService $service): RedirectResponse
    {
        $this->authorize('create', CommandeAchat::class);
        abort_if(! $request->user()->organization_id, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate(CommandeAchatService::reglesSaisie(), CommandeAchatService::messagesSaisie());

        $commande = $service->creer($request->user(), $data);

        return redirect()->route('achats.show', $commande)
            ->with('success', 'Bon de commande créé. Il doit maintenant être validé.');
    }
}
