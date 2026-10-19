<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Notifications\EcartReceptionTransfertNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ouvre, depuis la cloche du back-office, un signalement d'écart de réception (ADR 0032) : le
 * marque lu et redirige vers la fiche du transfert. 404 pour une notification d'un autre
 * utilisateur, comme l'API mobile ; l'accès à la fiche reste contrôlé par sa propre route.
 */
class OuvrirEcartReceptionController extends Controller
{
    public function __invoke(Request $request, string $notification): RedirectResponse
    {
        $signalement = $request->user()->notifications()
            ->where('type', EcartReceptionTransfertNotification::class)
            ->whereKey($notification)
            ->firstOrFail();

        $signalement->markAsRead();

        return redirect()->route('logistique.show', $signalement->data['resource']['id']);
    }
}
