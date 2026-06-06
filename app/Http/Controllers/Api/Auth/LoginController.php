<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'telephone' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            throw ValidationException::withMessages([
                'telephone' => 'Numéro de téléphone invalide.',
            ]);
        }

        $user = User::where('telephone', $phone)->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'telephone' => 'Les identifiants fournis sont incorrects.',
            ]);
        }

        if (! $user->hasVerifiedEmail() && ! $user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Veuillez vérifier votre adresse email pour activer votre compte. Consultez votre boîte de réception.',
                'code' => 'email_not_verified',
            ], 403);
        }

        if (! $user->is_active && ! $user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Votre compte a été bloqué. Veuillez contacter notre service client pour plus d\'informations.',
                'code' => 'account_blocked',
            ], 403);
        }

        $this->lierCompteParTelephone($user);

        $token = $user->createToken($request->input('device_name'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userResource($user),
        ]);
    }

    /**
     * Si le téléphone de l'utilisateur correspond à un livreur ou propriétaire
     * sans user_id, on établit le lien automatiquement.
     */
    private function lierCompteParTelephone(User $user): void
    {
        if (! $user->telephone) {
            return;
        }

        Livreur::where('telephone', $user->telephone)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);

        Proprietaire::where('telephone', $user->telephone)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);
    }

    private function userResource(User $user): array
    {
        return [
            'id' => $user->id,
            'prenom' => $user->prenom,
            'nom' => $user->nom,
            'telephone' => $user->telephone,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
        ];
    }
}
