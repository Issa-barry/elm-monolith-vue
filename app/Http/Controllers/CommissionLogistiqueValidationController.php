<?php

namespace App\Http\Controllers;

use App\Models\CommissionLogistique;
use Illuminate\Http\RedirectResponse;

class CommissionLogistiqueValidationController extends Controller
{
    /**
     * POST /logistique/commissions/{commission_logistique}/valider
     * Le statut brouillon n'existe plus — toutes les commissions démarrent en "impaye".
     */
    public function store(CommissionLogistique $commission_logistique): RedirectResponse
    {
        $commission_logistique->loadMissing('transfert');
        $this->authorize('genererCommission', $commission_logistique->transfert);

        return back()->with('info', 'Cette commission est déjà active.');
    }
}
