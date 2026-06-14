<?php

namespace App\Http\Requests;

use App\Enums\CategorieDepense;
use App\Models\DepenseType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('depenses.update');
    }

    public function rules(): array
    {
        $orgId = $this->user()->organization_id;

        $type = DepenseType::where('organization_id', $orgId)
            ->where('is_active', true)
            ->find($this->depense_type_id);

        $categorie = $type?->categorie;

        return [
            'depense_type_id' => [
                'required',
                'ulid',
                Rule::exists('depense_types', 'id')
                    ->where('organization_id', $orgId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'beneficiaire_id' => $this->beneficiaireRules($categorie, $orgId),
            'site_id' => [
                'nullable',
                'ulid',
                Rule::exists('sites', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
            ],
            'montant' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'date_depense' => ['required', 'date', 'before_or_equal:today'],
            'commentaire' => [
                ($type?->commentaire_obligatoire ? 'required' : 'nullable'),
                'string',
                'max:2000',
            ],
        ];
    }

    private function beneficiaireRules(?CategorieDepense $categorie, string $orgId): array
    {
        if (! $categorie || ! $categorie->needsBeneficiaire()) {
            return ['nullable'];
        }

        $table = $categorie->beneficiaireTable();

        return [
            'required',
            'ulid',
            Rule::exists($table, 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
        ];
    }

    public function messages(): array
    {
        return [
            'depense_type_id.required' => 'Le type de dépense est obligatoire.',
            'depense_type_id.exists' => 'Ce type de dépense est invalide ou inactif.',
            'beneficiaire_id.required' => 'Le bénéficiaire est obligatoire pour ce type de dépense.',
            'beneficiaire_id.exists' => 'Le bénéficiaire sélectionné est invalide.',
            'montant.required' => 'Le montant est obligatoire.',
            'montant.min' => 'Le montant doit être supérieur à 0.',
            'date_depense.required' => 'La date est obligatoire.',
            'date_depense.before_or_equal' => 'La date ne peut pas être dans le futur.',
            'commentaire.required' => 'Un commentaire est obligatoire pour ce type de dépense.',
        ];
    }
}
