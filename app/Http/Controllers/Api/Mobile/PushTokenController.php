<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expo_push_token' => ['required', 'string', 'max:200'],
        ]);

        $request->user()->update(['expo_push_token' => $data['expo_push_token']]);

        return response()->json(['message' => 'Token enregistré.']);
    }
}
