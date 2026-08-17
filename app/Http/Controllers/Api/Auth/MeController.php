<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Livreur;
use App\Models\Personne;
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
            'id' => $user->id,
            'prenom' => $user->prenom,
            'nom' => $user->nom,
            'telephone' => $user->telephone,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'is_active' => $user->is_active,
            'qr_payload' => $this->resolveQrPayload($user),
        ]);
    }

    private function resolveQrPayload(User $user): ?string
    {
        if (! $user->telephone) {
            return null;
        }

        // nom/prenom/telephone ne sont plus des colonnes de livreurs/proprietaires — l'identité
        // civile est portée par Personne (cf. Personne::normaliserTelephone()).
        $normalise = Personne::normaliserTelephone($user->telephone);

        $proprietaire = Proprietaire::whereHas('personne', fn ($q) => $q->where('telephone_normalise', $normalise))->first();
        if ($proprietaire) {
            return route('proprietaires.show', $proprietaire->id);
        }

        $livreur = Livreur::whereHas('personne', fn ($q) => $q->where('telephone_normalise', $normalise))->first();
        if ($livreur) {
            return route('livreurs.show', $livreur->id);
        }

        return null;
    }
}
