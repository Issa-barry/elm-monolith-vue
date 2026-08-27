<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ChangePasswordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.min' => 'Le nouveau mot de passe doit comporter au moins 8 caractères.',
            'password.confirmed' => 'La confirmation ne correspond pas.',
        ]);

        $user = $request->user();

        if (! Hash::check($request->string('current_password'), $user->password)) {
            return response()->json([
                'message' => 'Mot de passe actuel incorrect.',
                'errors' => ['current_password' => ['Le mot de passe actuel est incorrect.']],
            ], 422);
        }

        $user->update(['password' => Hash::make($request->string('password'))]);

        // Le token courant (cet appareil) reste valide, mais tous les AUTRES tokens
        // sont révoqués : si un token avait été compromis, ce changement volontaire de
        // mot de passe depuis un appareil légitime le déconnecte. Symétrique de
        // ResetController (mot de passe oublié), qui révoque TOUS les tokens — cf.
        // docs/api-auth-contract.md.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        return response()->json(['message' => 'Mot de passe mis à jour.']);
    }
}
