<?php

namespace App\Http\Requests\Settings;

use App\Enums\PrestataireType;
use App\Models\CommissionCibleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommissionConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('parametres.update');
    }

    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;
        $montantRules = ['required', 'regex:/^\d+$/', 'integer', 'min:0', 'max:99999999'];

        return [
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.categorie_id' => [
                'required',
                'string',
                'distinct',
                Rule::exists('categories', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('statut', 'actif'),
            ],
            'lignes.*.montants' => ['required', 'array', 'size:4'],
            'lignes.*.montants.'.CommissionCibleType::CODE_PROPRIETAIRE => $montantRules,
            'lignes.*.montants.'.CommissionCibleType::CODE_EQUIPE_LIVRAISON => $montantRules,
            'lignes.*.montants.'.CommissionCibleType::CODE_SITE => $montantRules,
            'lignes.*.montants.'.CommissionCibleType::CODE_CONSULTANT => $montantRules,
            'lignes.*.consultant_id' => [
                'required',
                'string',
                Rule::exists('prestataires', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('type', PrestataireType::CONSULTANT->value)
                    ->where('is_active', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'lignes.required' => 'Ajoutez au moins une catégorie autorisée.',
            'lignes.min' => 'Ajoutez au moins une catégorie autorisée.',
            'lignes.*.categorie_id.required' => 'Choisissez une catégorie.',
            'lignes.*.categorie_id.distinct' => 'Une catégorie ne peut être ajoutée qu’une seule fois.',
            'lignes.*.categorie_id.exists' => 'Cette catégorie n’est pas disponible pour votre organisation.',
            'lignes.*.consultant_id.required' => 'Choisissez le consultant bénéficiaire de cette catégorie.',
            'lignes.*.consultant_id.exists' => 'Ce consultant doit être actif et appartenir à votre organisation.',
            'lignes.*.montants.*.required' => 'Renseignez tous les montants. Utilisez 0 si aucune commission ne doit être versée.',
            'lignes.*.montants.*.regex' => 'Saisissez un montant entier, 0 ou plus.',
            'lignes.*.montants.*.integer' => 'Saisissez un montant entier, 0 ou plus.',
            'lignes.*.montants.*.min' => 'Saisissez un montant entier, 0 ou plus.',
        ];
    }
}
