<?php

namespace App\Http\Controllers\Achats;

use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Services\Achats\PerimetreCommandesAchat;
use Illuminate\Http\RedirectResponse;

class DestroyCommandeAchatController extends Controller
{
    public function __invoke(CommandeAchat $achat): RedirectResponse
    {
        $this->authorize('delete', $achat);
        app(PerimetreCommandesAchat::class)->autoriser($achat, auth()->user());
        abort_unless($achat->isAnnulee(), 403, 'Seules les commandes annulées peuvent être supprimées.');

        $achat->delete();

        return redirect()->route('achats.index')
            ->with('success', 'Commande supprimée.');
    }
}
