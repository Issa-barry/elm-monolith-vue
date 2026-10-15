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
 * Transfert du compte Mobile Money dédié d'un agent vers le Mobile Money de son agence (ADR 0027),
 * depuis l'écran Supports : crée un mouvement de fonds de nature `transfert_mobile_money` et
 * l'envoie (Envoyé) — le compte de l'agence n'augmente qu'à la confirmation de réception (écran
 * Mouvements). La référence de transaction et les frais sont obligatoires : les frais se saisissent
 * à chaque transfert, 0 compris. Toutes les règles (solde, destination, opérateur, unicité de la
 * référence) sont garanties par MouvementFondsService::transfererMobileMoneyAgent().
 */
class TransfererMobileMoneyAgentController extends Controller
{
    public function __invoke(Request $request, CompteTresorerie $compteTresorerie, MouvementFondsService $service): RedirectResponse
    {
        $user = auth()->user();

        abort_unless($compteTresorerie->organization_id === $user->organization_id, 403);
        $this->authorize('transfererMobileMoney', $compteTresorerie);

        // Le client envoie les valeurs brutes (le formatage « 800 000 » n'est qu'un affichage), mais on
        // tolère aussi une chaîne formatée (cf. MontantNormalizer). Des frais absents restent absents :
        // ils doivent être saisis, jamais supposés nuls.
        $request->merge(['montant' => MontantNormalizer::normalize($request->input('montant'))]);
        if ($request->filled('frais')) {
            $request->merge(['frais' => MontantNormalizer::normalize($request->input('frais'))]);
        }

        $data = $request->validate([
            'compte_tresorerie_destination_id' => ['required', Rule::exists('compta_supports_tresorerie', 'id')->where('organization_id', $user->organization_id)],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'frais' => ['required', 'numeric', 'min:0'],
            'reference_externe' => ['required', 'string', 'max:150'],
            'motif' => ['nullable', 'string', 'max:500'],
        ], [
            'compte_tresorerie_destination_id.required' => 'Le compte Mobile Money de l\'agence est obligatoire.',
            'compte_tresorerie_destination_id.exists' => 'Compte Mobile Money de destination introuvable.',
            'montant.required' => 'Le montant est obligatoire.',
            'montant.numeric' => 'Le montant doit être un nombre.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
            'frais.required' => 'Saisissez les frais du transfert, 0 si l\'opérateur n\'en a pas facturé.',
            'frais.numeric' => 'Les frais doivent être un nombre.',
            'frais.min' => 'Les frais ne peuvent pas être négatifs.',
            'reference_externe.required' => 'La référence de la transaction est obligatoire.',
        ]);

        $mouvement = $service->transfererMobileMoneyAgent(
            $user->organization_id,
            $compteTresorerie,
            $data['compte_tresorerie_destination_id'],
            (float) $data['montant'],
            (float) $data['frais'],
            $data['reference_externe'],
            $data['motif'] ?? null,
            $user->id,
        );

        return back()->with('success', "Transfert {$mouvement->reference} enregistré : en attente de confirmation de réception par l'agence.");
    }
}
