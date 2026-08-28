<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutAllController extends Controller
{
    /**
     * Révoque tous les tokens Sanctum de l'utilisateur (tous appareils/clients),
     * à la différence de LogoutController qui ne révoque que le token courant.
     * Utile après suspicion de compromission, ou depuis un écran "Sécurité".
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Déconnecté de tous les appareils.']);
    }
}
