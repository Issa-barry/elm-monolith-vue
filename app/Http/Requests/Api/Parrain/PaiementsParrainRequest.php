<?php

namespace App\Http\Requests\Api\Parrain;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtres de `GET /v1/mobile/parrain/paiements`. Aucun identifiant de parrain : il vient du compte
 * authentifié.
 */
class PaiementsParrainRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array{date_debut: ?string, date_fin: ?string} */
    public function filtres(): array
    {
        return [
            'date_debut' => $this->input('date_debut'),
            'date_fin' => $this->input('date_fin'),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 20);
    }
}
