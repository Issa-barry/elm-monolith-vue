<?php

namespace App\Http\Controllers\Api\Auth;

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
    public function __invoke(Request $request, OtpService $otp): JsonResponse
    {
        $request->validate([
            'telephone' => ['required', 'string'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            return response()->json(['error' => 'Numéro de téléphone invalide.'], 422);
        }

        if (User::where('telephone', $phone)->exists()) {
            return response()->json(['status' => 'user_exists']);
        }

        $prefill = null;

        $client = Client::where('telephone', $phone)->whereNull('user_id')->first();
        if ($client) {
            $prefill = ['prenom' => $client->prenom, 'nom' => $client->nom];
        }

        if (! $prefill) {
            $livreur = Livreur::where('telephone', $phone)->first();
            if ($livreur) {
                $prefill = ['prenom' => $livreur->prenom, 'nom' => $livreur->nom];
            }
        }

        if (! $prefill) {
            $proprietaire = Proprietaire::where('telephone', $phone)->whereNull('user_id')->first();
            if ($proprietaire) {
                $prefill = ['prenom' => $proprietaire->prenom, 'nom' => $proprietaire->nom];
            }
        }

        if (! $otp->canSend($phone)) {
            return response()->json(['error' => 'Trop de demandes de code. Veuillez réessayer plus tard.'], 429);
        }

        $otp->generate($phone);

        return response()->json([
            'status' => $prefill ? 'prefill_available' : 'not_found',
            'prefill' => $prefill,
        ]);
    }
}
