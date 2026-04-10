<?php

namespace App\Observers;

use App\Features\ModuleFeature;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Services\CashbackService;
use Laravel\Pennant\Feature;

class VenteObserver
{
    public function __construct(private readonly CashbackService $cashback) {}

    /**
     * Déclenche le traitement cashback après la création d'une vente.
     *
     * Le module CASHBACK doit être actif pour l'organisation concernée.
     * Si la vente n'a pas d'organisation ou que le module est inactif, on skipe silencieusement.
     */
    public function created(CommandeVente $vente): void
    {
        if (! $vente->organization_id) {
            return;
        }

        $org = Organization::find($vente->organization_id);
        if (! $org || ! Feature::for($org)->active(ModuleFeature::CASHBACK)) {
            return;
        }

        $this->cashback->processVente($vente);
    }
}
