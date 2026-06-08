<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushNotificationService
{
    private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    /**
     * Envoie une notification push à plusieurs tokens Expo en une seule requête.
     *
     * @param  string[]  $tokens
     * @param  array<string, mixed>  $data
     */
    public function sendMany(array $tokens, string $title, string $body, array $data = []): void
    {
        $tokens = array_values(array_filter($tokens));
        if (empty($tokens)) {
            return;
        }

        $messages = array_map(fn (string $token) => [
            'to' => $token,
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            'data' => $data,
        ], $tokens);

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Accept-Encoding' => 'gzip, deflate',
            'Content-Type' => 'application/json',
        ])->post(self::EXPO_PUSH_URL, $messages);

        if ($response->failed()) {
            Log::warning('ExpoPush: échec de l\'envoi', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
