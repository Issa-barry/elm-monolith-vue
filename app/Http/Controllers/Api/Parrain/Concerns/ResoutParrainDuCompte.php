<?php

namespace App\Http\Controllers\Api\Parrain\Concerns;

use App\Models\Parrain;
use App\Services\Comptes\ActivitesCompteResolver;
use Illuminate\Http\Request;

/**
 * Le parrain vient toujours du compte authentifié, recalculé à chaque requête, jamais d'un paramètre :
 * un compte détaché perd l'accès dès la requête suivante (COMPTE-005).
 */
trait ResoutParrainDuCompte
{
    private function parrainDuCompte(Request $request): Parrain
    {
        $parrain = app(ActivitesCompteResolver::class)->resolve($request->user())->parrain;

        abort_if($parrain === null, 404, "Aucun parrain n'est rattaché à ce compte.");

        return $parrain;
    }
}
