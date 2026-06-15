<?php

namespace App\Http\Controllers;

use App\Models\CommandeVente;
use App\Models\CommissionVente;
use Illuminate\Http\RedirectResponse;

class CommissionVenteValidationController extends Controller
{
    /**
     * POST /commissions/{commission_vente}/valider
     * Le statut brouillon n'existe plus — toutes les commissions démarrent en "impaye".
     * Ce contrôleur est conservé pour compatibilité de route mais ne fait rien d'utile.
     */
    public function store(CommissionVente $commission_vente): RedirectResponse
    {
        $this->authorize('viewAny', CommandeVente::class);

        return back()->with('info', 'Cette commission est déjà active.');
    }
}
