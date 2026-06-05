<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id'         => $user->id,
            'prenom'     => $user->prenom,
            'nom'        => $user->nom,
            'telephone'  => $user->telephone,
            'email'      => $user->email,
            'roles'      => $user->getRoleNames(),
            'is_active'  => $user->is_active,
            'qr_payload' => $this->resolveQrPayload($user),
        ]);
    }

    private function resolveQrPayload(User $user): ?string
    {
        if (! $user->telephone) {
            return null;
        }

        $proprietaire = Proprietaire::where('telephone', $user->telephone)->first();
        if ($proprietaire) {
            return route('proprietaires.show', $proprietaire->id);
        }

        $livreur = Livreur::where('telephone', $user->telephone)->first();
        if ($livreur) {
            return route('livreurs.show', $livreur->id);
        }

        return null;
    }
}
