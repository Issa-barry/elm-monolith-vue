<?php

namespace App\Http\Requests\Produits;

use App\Enums\ModeRemiseGrossiste;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategorieTarifsGrossisteRequest extends FormRequest
{
    /**
     * Autorisation réelle déléguée à CategorieTarifGrossisteController::update()
     * ($this->authorize('update', $client), même policy que la fiche client elle-même — les
     * tarifs Grossiste sont un sous-résultat du client, pas une permission séparée) : ce
     * FormRequest ne porte que les règles de validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $orgId = $this->user()->organization_id;

        return [
            // 'present' et non 'required' : un tableau vide est légitime (toutes les lignes
            // retirées via l'UI, cf. Clients/Show.vue) — CategorieTarifGrossisteController::
            // update() supprime alors la totalité des tarifs de ce client.
            'tarifs' => ['present', 'array'],
            'tarifs.*.categorie_id' => ['required', Rule::exists('categories', 'id')->where('organization_id', $orgId)],
            'tarifs.*.mode' => ['required', Rule::in(ModeRemiseGrossiste::values())],
            'tarifs.*.prix' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'tarifs.*.categorie_id.required' => 'La catégorie est obligatoire pour chaque tarif.',
            'tarifs.*.categorie_id.exists' => 'Catégorie introuvable.',
            'tarifs.*.mode.in' => 'Mode de remise invalide.',
            'tarifs.*.prix.required' => 'Le prix est obligatoire pour chaque tarif.',
            'tarifs.*.prix.min' => 'Le prix ne peut pas être négatif.',
        ];
    }
}
