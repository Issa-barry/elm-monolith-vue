<?php

namespace App\Support\Clients;

use App\Features\ModuleFeature;
use App\Models\CashbackSolde;
use App\Models\Client;
use App\Models\Organization;
use Laravel\Pennant\Feature;

/**
 * Widget solde cashback d'un client — extrait de ClientController (dupliqué à l'identique entre
 * show() et edit()), affiché uniquement si le module Cashback est actif pour l'organisation.
 * Retourne un solde à zéro (pas null) si le module est actif mais qu'aucune ligne CashbackSolde
 * n'existe encore pour ce client — seule l'absence du module retourne null.
 */
final class ClientCashbackSoldeFormatter
{
    public static function pour(Client $client): ?array
    {
        $org = auth()->user()->organization_id
            ? Organization::find(auth()->user()->organization_id)
            : null;

        if (! $org || ! Feature::for($org)->active(ModuleFeature::CASHBACK)) {
            return null;
        }

        $solde = CashbackSolde::where('organization_id', $client->organization_id)
            ->where('client_id', $client->id)
            ->first();

        return $solde ? [
            'cumul_achats' => $solde->cumul_achats,
            'cashback_en_attente' => $solde->cashback_en_attente,
            'total_cashback_gagne' => $solde->total_cashback_gagne,
            'total_cashback_verse' => $solde->total_cashback_verse,
        ] : [
            'cumul_achats' => 0,
            'cashback_en_attente' => 0,
            'total_cashback_gagne' => 0,
            'total_cashback_verse' => 0,
        ];
    }
}
