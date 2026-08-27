<?php

namespace App\Http\Requests\Api\Client;

use App\Enums\StatutCommandeVente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommandesMineRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'statut' => ['nullable', Rule::in(array_column(StatutCommandeVente::cases(), 'value'))],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): array
    {
        return [
            'statut' => $this->input('statut'),
            'date_debut' => $this->input('date_debut'),
            'date_fin' => $this->input('date_fin'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 20);
    }
}
