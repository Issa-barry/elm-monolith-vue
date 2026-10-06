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
 * Approvisionnement de la caisse dédiée d'un agent depuis une caisse de l'agence, depuis l'écran
 * Supports (ADR 0018) : crée un mouvement de fonds `approvisionnement_caisse` et l'envoie (Envoyé) —
 * la caisse de l'agent n'augmente qu'à la confirmation de réception par l'agent lui-même. Toutes les
 * règles (type et état des caisses, même agence, envoyeur ≠ bénéficiaire, solde) sont garanties par
 * MouvementFondsService::approvisionnerCaisseAgent(), pas par ce contrôleur.
 */
class ApprovisionnerCaisseAgentController extends Controller
{
    public function __invoke(Request $request, CompteTresorerie $compteTresorerie, MouvementFondsService $service): RedirectResponse
    {
        $user = auth()->user();

        abort_unless($compteTresorerie->organization_id === $user->organization_id, 403);
        $this->authorize('approvisionner', $compteTresorerie);

        $request->merge(['montant' => MontantNormalizer::normalize($request->input('montant'))]);

        $data = $request->validate([
            'compte_tresorerie_destination_id' => ['required', Rule::exists('compta_supports_tresorerie', 'id')->where('organization_id', $user->organization_id)],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'motif' => ['nullable', 'string', 'max:500'],
        ], [
            'compte_tresorerie_destination_id.required' => 'La caisse de l\'agent est obligatoire.',
            'compte_tresorerie_destination_id.exists' => 'Caisse de l\'agent introuvable.',
            'montant.required' => 'Le montant est obligatoire.',
            'montant.numeric' => 'Le montant doit être un nombre.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
        ]);

        $mouvement = $service->approvisionnerCaisseAgent(
            $user->organization_id,
            $compteTresorerie,
            $data['compte_tresorerie_destination_id'],
            (float) $data['montant'],
            $data['motif'] ?? null,
            $user->id,
        );

        return back()->with('success', "Approvisionnement {$mouvement->reference} envoyé : en attente de confirmation de réception par l'agent.");
    }
}
