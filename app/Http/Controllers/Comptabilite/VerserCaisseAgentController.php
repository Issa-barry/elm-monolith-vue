<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\CompteTresorerie;
use App\Services\Tresorerie\MouvementFondsService;
use App\Support\MontantNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Versement d'une caisse dédiée à un agent vers une caisse de l'agence, depuis l'écran Supports :
 * crée un mouvement de fonds de nature `interne_caisses` et l'envoie (Envoyé) — la caisse de
 * l'agence n'augmente qu'à la confirmation de réception par un autre utilisateur (écran Mouvements).
 * Toutes les règles (solde, destination, caisse active) sont garanties par
 * MouvementFondsService::verserCaisseAgent(), pas par ce contrôleur.
 */
class VerserCaisseAgentController extends Controller
{
    public function __invoke(Request $request, CompteTresorerie $compteTresorerie, MouvementFondsService $service): RedirectResponse
    {
        $user = auth()->user();

        abort_unless($compteTresorerie->organization_id === $user->organization_id, 403);
        $this->authorize('verser', $compteTresorerie);

        // Le client envoie la valeur brute (le formatage « 800 000 » n'est qu'un affichage), mais on
        // tolère aussi une chaîne formatée (cf. MontantNormalizer).
        $request->merge(['montant' => MontantNormalizer::normalize($request->input('montant'))]);

        $data = $request->validate([
            'compte_tresorerie_destination_id' => ['required', Rule::exists('compta_supports_tresorerie', 'id')->where('organization_id', $user->organization_id)],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'motif' => ['nullable', 'string', 'max:500'],
        ], [
            'compte_tresorerie_destination_id.required' => 'La caisse de destination est obligatoire.',
            'compte_tresorerie_destination_id.exists' => 'Caisse de destination introuvable.',
            'montant.required' => 'Le montant est obligatoire.',
            'montant.numeric' => 'Le montant doit être un nombre.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
        ]);

        $mouvement = $service->verserCaisseAgent(
            $user->organization_id,
            $compteTresorerie,
            $data['compte_tresorerie_destination_id'],
            (float) $data['montant'],
            $data['motif'] ?? null,
            $user->id,
        );

        return back()->with('success', "Versement {$mouvement->reference} envoyé : en attente de confirmation de réception par un autre utilisateur.");
    }
}
