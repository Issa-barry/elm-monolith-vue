<?php

namespace App\Http\Controllers\Parrains;

use App\Http\Controllers\Controller;
use App\Models\Parrain;
use App\Models\User;
use App\Services\Comptes\RattachementCompteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Rattachement manuel d'un compte existant à un parrain (ADR 0050, COMPTE-001 et COMPTE-002). La
 * recherche ne fait que proposer un compte : seul l'envoi de la confirmation du responsable le relie.
 */
class CompteParrainController extends Controller
{
    public function __construct(private readonly RattachementCompteService $rattachement) {}

    public function candidat(Request $request, Parrain $parrain): JsonResponse
    {
        $this->authorize('gererCompte', $parrain);

        $data = $request->validate([
            'telephone' => ['required', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
        ]);

        $compte = $this->rattachement->compteParTelephone($data['telephone']);

        return response()->json([
            'compte' => $compte ? $this->rattachement->apercu($parrain, $compte) : null,
        ]);
    }

    public function store(Request $request, Parrain $parrain): RedirectResponse
    {
        $this->authorize('gererCompte', $parrain);

        $data = $request->validate([
            'compte_id' => ['required', 'string'],
            'identite_verifiee' => ['accepted'],
        ], [
            'identite_verifiee.accepted' => "Confirmez avoir vérifié l'identité de cette personne.",
        ]);

        $this->rattachement->rattacher($parrain, User::query()->findOrFail($data['compte_id']), $request->user());

        return back()->with('success', 'Compte rattaché au parrain.');
    }

    public function destroy(Request $request, Parrain $parrain): RedirectResponse
    {
        $this->authorize('gererCompte', $parrain);

        $this->rattachement->detacher($parrain, $request->user());

        return back()->with('success', 'Compte détaché du parrain. Son accès est coupé, le compte est conservé.');
    }
}
