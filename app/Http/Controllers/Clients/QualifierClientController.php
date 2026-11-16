<?php

namespace App\Http\Controllers\Clients;

use App\Enums\ClientType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Client\QualificationClientService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Qualifie un client créé à l'inscription d'un livreur ou d'un propriétaire (ADR 0066) : il devient
 * utilisable dans les ventes, avec la tarification de la nature choisie.
 */
class QualifierClientController extends Controller
{
    public function __invoke(Request $request, Client $client, QualificationClientService $qualification): RedirectResponse
    {
        $this->authorize('qualifier', $client);

        $data = $request->validate([
            'type' => ['required', Rule::in(ClientType::values())],
            'cashback_eligible' => 'boolean',
            'cashback_montant_par_pack' => 'nullable|integer|min:1',
        ], [
            'type.required' => 'Choisissez la nature commerciale du client.',
            'type.in' => 'La nature commerciale choisie est invalide.',
        ]);

        $qualification->qualifier($client, $request->user(), $data);

        return back()->with('success', 'Client qualifié : il peut maintenant être choisi dans une vente.');
    }
}
