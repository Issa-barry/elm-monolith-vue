<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id'        => $user->id,
            'prenom'    => $user->prenom,
            'nom'       => $user->nom,
            'telephone' => $user->telephone,
            'email'     => $user->email,
            'roles'     => $user->getRoleNames(),
            'is_active' => $user->is_active,
        ]);
    }
}
