<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegisterLookupController extends Controller
{
    /**
     * Recherche un numéro de téléphone dans toutes les tables métier et génère un OTP.
     *
     * Retourne :
     *  - user_exists        → numéro déjà associé à un compte Users
     *  - prefill_available  → nom/prénom trouvés dans clients/livreurs/proprietaires
     *  - not_found          → aucune correspondance (inscription classique)
     *
     * Dans les deux derniers cas, un OTP est généré et stocké en session.
     */
    public function __invoke(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate([
            'telephone' => ['required', 'string'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            return response()->json([
                'error' => 'Numéro de téléphone invalide.',
            ], 422);
        }

        // 1. Téléphone déjà dans users → orienter vers connexion
        if (User::where('telephone', $phone)->exists()) {
            return response()->json(['status' => 'user_exists']);
        }

        // 2. Rechercher un préremplissage dans les tables métier
        $prefill = null;

        // Priorité : client sans user_id
        $client = Client::where('telephone', $phone)->whereNull('user_id')->first();
        if ($client) {
            $prefill = ['prenom' => $client->prenom, 'nom' => $client->nom];
        }

        // Livreur (pas de user_id dans la table livreurs)
        if (! $prefill) {
            $livreur = Livreur::where('telephone', $phone)->first();
            if ($livreur) {
                $prefill = ['prenom' => $livreur->prenom, 'nom' => $livreur->nom];
            }
        }

        // Propriétaire sans user_id
        if (! $prefill) {
            $proprietaire = Proprietaire::where('telephone', $phone)->whereNull('user_id')->first();
            if ($proprietaire) {
                $prefill = ['prenom' => $proprietaire->prenom, 'nom' => $proprietaire->nom];
            }
        }

        // 3. Générer l'OTP (stocké en session, pas encore envoyé par SMS en MVP)
        $otp->generate($phone);

        return response()->json([
            'status' => $prefill ? 'prefill_available' : 'not_found',
            'prefill' => $prefill,
        ]);
    }
}
