<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\CheckPhoneRequest;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CheckPhoneController extends Controller
{
    public function __invoke(CheckPhoneRequest $request, RegistrationService $service): JsonResponse
    {
        try {
            $result = $service->lookupPhone($request->input('telephone'));
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Les données fournies sont invalides.',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json($result);
    }
}
