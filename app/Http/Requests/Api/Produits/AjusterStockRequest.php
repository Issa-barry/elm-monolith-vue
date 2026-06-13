<?php

namespace App\Http\Requests\Api\Produits;

use App\Enums\MotifAjustementStock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AjusterStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'augmenter'    => ['nullable', 'integer', 'min:1'],
            'diminuer'     => ['nullable', 'integer', 'min:1'],
            'motif_type'   => ['required', Rule::in(MotifAjustementStock::validValues())],
            'motif_detail' => ['nullable', 'required_if:motif_type,autre', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->has('motif_type')) {
                return;
            }

            $motifType    = $this->input('motif_type');
            $hasAugmenter = filled($this->input('augmenter'));
            $hasDiminuer  = filled($this->input('diminuer'));

            // Direction indéterminée : les autres règles géreront l'absence
            if (! $hasAugmenter && ! $hasDiminuer) {
                return;
            }

            $direction    = $hasAugmenter ? 'entree' : 'sortie';
            $validMotifs  = MotifAjustementStock::validValuesForDirection($direction);

            if (! in_array($motifType, $validMotifs, true)) {
                $validator->errors()->add(
                    'motif_type',
                    'Ce motif n\'est pas valide pour ce type d\'ajustement.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'augmenter.integer'        => 'La quantité doit être un nombre entier.',
            'augmenter.min'            => 'La quantité doit être supérieure à 0.',
            'diminuer.integer'         => 'La quantité doit être un nombre entier.',
            'diminuer.min'             => 'La quantité doit être supérieure à 0.',
            'motif_type.required'      => 'Le motif est obligatoire.',
            'motif_type.in'            => 'Le motif sélectionné est invalide.',
            'motif_detail.required_if' => 'Veuillez préciser le motif.',
            'motif_detail.max'         => 'Le détail du motif ne peut pas dépasser 500 caractères.',
        ];
    }
}
