<?php

namespace App\Http\Requests\Api\Parrain;

use App\Enums\StatutCommission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de `GET /v1/mobile/parrain/commissions`. Aucun identifiant de parrain : il vient du compte
 * authentifié.
 */
class CommissionsParrainRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vehicule_id' => ['nullable', 'string'],
            'statut' => ['nullable', Rule::in([
                StatutCommission::CREEE->value,
                StatutCommission::IMPAYE->value,
                StatutCommission::PARTIEL->value,
                StatutCommission::PAYE->value,
            ])],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array{vehicule_id: ?string, statut: ?string, date_debut: ?string, date_fin: ?string} */
    public function filtres(): array
    {
        return [
            'vehicule_id' => $this->input('vehicule_id'),
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
