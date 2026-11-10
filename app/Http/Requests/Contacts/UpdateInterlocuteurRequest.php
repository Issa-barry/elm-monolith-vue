<?php

namespace App\Http\Requests\Contacts;

use App\Models\EntrepriseTierce;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Modification d'un interlocuteur (ADR 0060, lot 4) : fonction dans l'entreprise et notes
 * seulement. Les coordonnées appartiennent à la fiche Personne (modification au lot 7).
 */
class UpdateInterlocuteurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gererInterlocuteurs', EntrepriseTierce::class);
    }

    public function rules(): array
    {
        return [
            'fonction' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
