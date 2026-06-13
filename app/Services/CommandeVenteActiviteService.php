<?php

namespace App\Services;

use App\Models\CommandeVente;
use App\Models\CommandeVenteActivite;

class CommandeVenteActiviteService
{
    public static function log(
        CommandeVente $commande,
        string $action,
        array $details = [],
        ?string $userId = null,
    ): void {
        CommandeVenteActivite::create([
            'commande_vente_id' => $commande->id,
            'user_id'           => $userId ?? auth()->id(),
            'action'            => $action,
            'details'           => $details ?: null,
        ]);
    }
}
