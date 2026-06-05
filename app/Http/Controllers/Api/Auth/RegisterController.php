<?php

namespace App\Http\Controllers\Api\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    public function __invoke(Request $request, CreateNewUser $creator): JsonResponse
    {
        $request->validate([
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $user = $creator->create($request->all());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données fournies sont invalides.',
                'errors' => $e->errors(),
            ], 422);
        }

        $token = $user->createToken($request->input('device_name'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'prenom' => $user->prenom,
                'nom' => $user->nom,
                'telephone' => $user->telephone,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
            ],
        ], 201);
    }
}
