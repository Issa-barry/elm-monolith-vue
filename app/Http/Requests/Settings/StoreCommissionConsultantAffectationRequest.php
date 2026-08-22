<?php

namespace App\Http\Requests\Settings;

use App\Enums\PrestataireType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommissionConsultantAffectationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('parametres.update');
    }

    public function rules(): array
    {
        return [
            // Le prestataire doit appartenir à l'organisation courante, être actif et de type
            // Consultant — jamais un prestataire arbitraire (ex: un mécanicien) ni celui d'une
            // autre organisation.
            'prestataire_id' => ['required', 'string', Rule::exists('prestataires', 'id')
                ->where('organization_id', $this->user()->organization_id)
                ->where('type', PrestataireType::CONSULTANT->value)
                ->where('is_active', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'prestataire_id.required' => 'Choisissez un prestataire.',
            'prestataire_id.exists' => 'Ce prestataire doit être actif, de type Consultant, et appartenir à votre organisation.',
        ];
    }
}
