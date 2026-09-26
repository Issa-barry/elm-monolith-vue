<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\CashbackEligibiliteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Met à jour uniquement la configuration cashback depuis la fiche client, sans réémettre les
 * autres informations du client. La même règle métier que le formulaire complet reste appliquée :
 * un Revendeur ne peut pas être désactivé et tout cashback actif exige un montant par pack
 * strictement positif.
 */
class UpdateCashbackClientController extends Controller
{
    public function __invoke(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'cashback_eligible' => 'required|boolean',
            'cashback_montant_par_pack' => 'nullable|integer|min:1',
        ]);

        CashbackEligibiliteService::validerCoherence(
            $client->type->value,
            (bool) $data['cashback_eligible'],
            $data['cashback_montant_par_pack'] ?? null,
        );

        $client->update([
            'cashback_eligible' => $data['cashback_eligible'],
            'cashback_montant_par_pack' => $data['cashback_montant_par_pack'] ?? null,
        ]);

        return back()->with('success', 'Configuration cashback mise à jour.');
    }
}
