<?php

namespace App\Http\Controllers;

use App\Models\Livreur;
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

        $proprietaire = Proprietaire::where('telephone', $user->telephone)->first();
        if ($proprietaire) {
            return response()->json(['url' => route('proprietaires.show', $proprietaire->id)]);
        }

        $livreur = Livreur::where('telephone', $user->telephone)->first();
        if ($livreur) {
            return response()->json(['url' => route('livreurs.show', $livreur->id)]);
        }

        return response()->json(['url' => null, 'message' => 'Aucun profil propriétaire ou livreur trouvé.'], 404);
    }
}
