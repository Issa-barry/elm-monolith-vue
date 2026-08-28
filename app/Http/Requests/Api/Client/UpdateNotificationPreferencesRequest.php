<?php

namespace App\Http\Requests\Api\Client;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array'],
            'preferences.*' => ['boolean'],
        ];
    }
}
