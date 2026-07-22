<?php

namespace App\Http\Requests;

use App\Enums\TypePieceIdentite;
use App\Models\PieceIdentite;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePieceIdentiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employe = $this->route('employe');

        return (bool) $employe && $this->user()->can('create', [PieceIdentite::class, $employe]);
    }

    public function rules(): array
    {
        return [
            'type_piece' => ['required', Rule::in(TypePieceIdentite::values())],
            'numero' => ['nullable', 'string', 'max:100'],
            'pays_delivrance' => ['nullable', 'string', 'size:2'],
            'date_delivrance' => ['nullable', 'date'],
            'date_expiration' => ['nullable', 'date', 'after_or_equal:date_delivrance'],
            'recto' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'verso' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'type_piece.required' => 'Le type de pièce est obligatoire.',
            'type_piece.in' => 'Type de pièce invalide.',
            'date_expiration.after_or_equal' => "La date d'expiration doit être postérieure ou égale à la date de délivrance.",
            'recto.required' => 'Le recto de la pièce est obligatoire.',
            'recto.mimes' => 'Le recto doit être un PDF, JPG, JPEG ou PNG.',
            'verso.mimes' => 'Le verso doit être un PDF, JPG, JPEG ou PNG.',
            'recto.max' => 'Le recto ne doit pas dépasser 5 Mo.',
            'verso.max' => 'Le verso ne doit pas dépasser 5 Mo.',
        ];
    }
}
