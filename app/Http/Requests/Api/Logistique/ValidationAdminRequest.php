<?php

namespace App\Http\Requests\Api\Logistique;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValidationAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision'       => ['required', Rule::in(['accord', 'refus', 'invalider'])],
            'motif'          => ['nullable', 'required_if:decision,refus', 'string', 'max:1000'],
            'montant_par_pack' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'La décision est requise.',
            'decision.in'       => 'La décision doit être accord, refus ou invalider.',
            'motif.required_if' => 'Le motif est requis en cas de refus.',
        ];
    }
}
