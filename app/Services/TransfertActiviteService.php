<?php

namespace App\Services;

use App\Models\TransfertActivite;
use App\Models\TransfertLogistique;

class TransfertActiviteService
{
    public static function log(
        TransfertLogistique $transfert,
        string $action,
        array $details = [],
        ?int $userId = null,
    ): void {
        TransfertActivite::create([
            'transfert_logistique_id' => $transfert->id,
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'details' => $details ?: null,
        ]);
    }
}
