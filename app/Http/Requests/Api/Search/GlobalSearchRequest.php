<?php

namespace App\Http\Requests\Api\Search;

use Illuminate\Foundation\Http\FormRequest;

class GlobalSearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'q.required' => 'Le paramètre q est obligatoire.',
            'q.min' => 'Le paramètre q doit contenir au moins 2 caractères.',
        ];
    }
}
