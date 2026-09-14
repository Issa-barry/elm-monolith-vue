<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\CategorieTarifGrossiste;
use App\Models\Client;
use Illuminate\Http\JsonResponse;

class ShowTarifsGrossisteClientController extends Controller
{
    /**
     * Grille du client — fetch live depuis Ventes/Create.vue/Edit.vue au choix d'un client
     * Grossiste (le mode/la catégorie ne sont connus qu'à ce moment-là). Gatée par la permission
     * de vente (créer une commande implique de voir le tarif applicable), pas par une permission
     * d'administration séparée.
     */
    public function __invoke(Client $client): JsonResponse
    {
        $user = auth()->user();
        abort_unless($user->can('ventes.create') || $user->can('ventes.update'), 403);
        abort_unless($client->organization_id === $user->organization_id, 403);

        return response()->json(
            CategorieTarifGrossiste::gridForClient($client->organization_id, $client->id)
        );
    }
}
