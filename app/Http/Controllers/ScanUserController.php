<?php

namespace App\Http\Controllers;

use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Résout un ULID utilisateur en URL de fiche backoffice.
 * Utilisé par useScanInterceptor quand le QR code du mobile (qui encode
 * seulement user.id) est scanné depuis le backoffice.
 */
class ScanUserController extends Controller
{
    public function __invoke(Request $request, string $userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return response()->json(['url' => null, 'message' => 'Utilisateur introuvable.'], 404);
        }

        if (! $user->telephone) {
            return response()->json(['url' => null, 'message' => 'Aucun profil propriétaire ou livreur trouvé.'], 404);
        }

        // nom/prenom/telephone ne sont plus des colonnes de livreurs/proprietaires — l'identité
        // civile est portée par Personne (cf. Personne::normaliserTelephone()).
        $normalise = Personne::normaliserTelephone($user->telephone);

        $proprietaire = Proprietaire::whereHas('personne', fn ($q) => $q->where('telephone_normalise', $normalise))->first();
        if ($proprietaire) {
            return response()->json(['url' => route('proprietaires.show', $proprietaire->id)]);
        }

        $livreur = Livreur::whereHas('personne', fn ($q) => $q->where('telephone_normalise', $normalise))->first();
        if ($livreur) {
            return response()->json(['url' => route('livreurs.show', $livreur->id)]);
        }

        return response()->json(['url' => null, 'message' => 'Aucun profil propriétaire ou livreur trouvé.'], 404);
    }
}
