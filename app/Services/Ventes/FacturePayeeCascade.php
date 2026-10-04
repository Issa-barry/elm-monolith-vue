<?php

namespace App\Services\Ventes;

use App\Features\ModuleFeature;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Services\CashbackService;
use Laravel\Pennant\Feature;

/**
 * Ce qui suit le passage d'une facture de vente à « Payée » hors commission (la commission est déjà
 * déclenchée par FactureVente::recalculStatut()) : le cashback du client. Point unique partagé par
 * l'encaissement d'une facture (Ventes\StoreEncaissementVenteController) et la remise d'une
 * précommande déjà intégralement payée par ses acomptes (ADR 0019, constat C9) — avant lui, le
 * cashback n'était déclenché que par le contrôleur d'encaissement. Idempotent :
 * CashbackService::processVente() ne crée jamais deux gains pour une même vente.
 */
final class FacturePayeeCascade
{
    public static function apresPassageEnPayee(?CommandeVente $commande): void
    {
        if (! $commande || ! $commande->organization_id || ! $commande->client_id) {
            return;
        }

        $org = Organization::find($commande->organization_id);
        if ($org && Feature::for($org)->active(ModuleFeature::CASHBACK)) {
            app(CashbackService::class)->processVente($commande);
        }
    }
}
