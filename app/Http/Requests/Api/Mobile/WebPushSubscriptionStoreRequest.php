<?php

namespace App\Http\Requests\Api\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload standard `PushSubscription.toJSON()` — cf.
 * https://developer.mozilla.org/docs/Web/API/PushSubscription/toJSON.
 * Bornes de taille explicites (point sécurité du rapport Web Push) :
 * un `endpoint`/des clés anormalement longs sont rejetés plutôt
 * qu'acceptés silencieusement.
 */
class WebPushSubscriptionStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'url', 'max:2048'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ];
    }
}
