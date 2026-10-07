<?php

namespace App\Http\Controllers\Achats;

use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Services\Achats\CommandeAchatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateCommandeAchatController extends Controller
{
    public function __invoke(Request $request, CommandeAchat $achat, CommandeAchatService $service): RedirectResponse
    {
        $this->authorize('update', $achat);

        $data = $request->validate(CommandeAchatService::reglesSaisie(), CommandeAchatService::messagesSaisie());

        $service->modifier($achat, $request->user(), $data);

        return redirect()->route('achats.show', $achat)
            ->with('success', 'Bon de commande modifié.');
    }
}
