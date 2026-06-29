<?php

namespace App\Http\Controllers\Api\Auth;

use App\DTOs\RegisterData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, RegistrationService $service): JsonResponse
    {
        $data = new RegisterData(
            telephone: $request->input('telephone'),
            prenom: $request->input('prenom'),
            nom: $request->input('nom'),
            password: $request->input('password'),
        );

        try {
            $user = $service->register($data);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données fournies sont invalides.',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'message' => 'Compte créé avec succès.',
            'user' => new UserResource($user),
        ], 201);
    }
}
