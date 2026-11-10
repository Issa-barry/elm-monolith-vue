<?php

namespace App\Http\Requests\Settings;

use App\Models\Site;
use App\Services\Commission\AgencePayeuseCommissionService;
use App\Services\Tresorerie\SiteCentralTresorerieResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgencesPayeusesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('commissions.configurer_agences_payeuses');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $siteIds = Site::where('organization_id', $this->user()->organization_id)->pluck('id')->all();
        $types = AgencePayeuseCommissionService::TYPES;

        $rules = [
            'agence_centralisee_id' => ['nullable', 'string', Rule::in($siteIds)],
            'regles' => ['required', 'array'],
        ];

        foreach ($types as $type) {
            $rules["regles.{$type}"] = ['required', 'array'];
            $rules["regles.{$type}.mode"] = ['required', 'string', Rule::in(AgencePayeuseCommissionService::MODES)];
            $rules["regles.{$type}.site_id"] = [
                'nullable',
                'string',
                Rule::requiredIf(fn () => $this->input("regles.{$type}.mode") === AgencePayeuseCommissionService::MODE_AGENCE),
                Rule::in($siteIds),
            ];
        }

        return $rules;
    }

    /** Une catégorie « agence centralisée » exige qu'une agence centralisée existe. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || $this->filled('agence_centralisee_id')) {
                return;
            }

            $centralisees = collect((array) $this->input('regles', []))
                ->filter(fn ($regle) => ($regle['mode'] ?? null) === AgencePayeuseCommissionService::MODE_CENTRALISEE);

            if ($centralisees->isNotEmpty() && app(SiteCentralTresorerieResolver::class)->centralOuNull($this->user()->organization_id) === null) {
                $validator->errors()->add('agence_centralisee_id', 'Choisissez l\'agence centralisée : aucune trésorerie principale n\'est désignée.');
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'agence_centralisee_id.in' => 'Cette agence n\'appartient pas à votre organisation.',
            'regles.*.mode.in' => 'Règle de paiement inconnue.',
            'regles.*.site_id.required' => 'Choisissez l\'agence qui paie cette catégorie.',
            'regles.*.site_id.in' => 'Cette agence n\'appartient pas à votre organisation.',
        ];
    }
}
