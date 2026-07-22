<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejeterPieceIdentiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('rejeter', $this->route('pieceIdentite'));
    }

    public function rules(): array
    {
        return [
            'motif_rejet' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'motif_rejet.required' => 'Le motif du rejet est obligatoire.',
        ];
    }
}
