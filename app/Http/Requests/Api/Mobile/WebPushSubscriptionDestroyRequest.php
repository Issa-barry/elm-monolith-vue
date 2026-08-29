<?php

namespace App\Http\Requests\Api\Mobile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `endpoint` en query string (`DELETE .../subscriptions?endpoint=...`), pas
 * un corps JSON — un DELETE avec corps est mal supporté par certains
 * clients/proxys et non documentable proprement en OpenAPI (Scramble
 * n'infère pas de requestBody sur DELETE, conformément à la convention REST
 * qui déconseille un corps sur cette méthode).
 */
class WebPushSubscriptionDestroyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'url', 'max:2048'],
        ];
    }
}
