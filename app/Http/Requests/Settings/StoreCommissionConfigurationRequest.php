<?php

namespace App\Http\Requests\Settings;

use App\Enums\PrestataireType;
use App\Models\CommissionCibleType;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommissionConfigurationRequest extends FormRequest
{
    private const CIBLE_CODES = [
        CommissionCibleType::CODE_PROPRIETAIRE,
        CommissionCibleType::CODE_EQUIPE_LIVRAISON,
        CommissionCibleType::CODE_SITE,
        CommissionCibleType::CODE_CONSULTANT,
    ];

    public function authorize(): bool
    {
        return $this->user()->can('parametres.update');
    }

    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

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
            'lignes.*.beneficiaires' => ['required', 'array', 'min:1'],
            'lignes.*.beneficiaires.*' => ['distinct', Rule::in(self::CIBLE_CODES)],
            'lignes.*.consultant_id' => [
                'nullable',
                'string',
                Rule::exists('prestataires', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('type', PrestataireType::CONSULTANT->value)
                    ->where('is_active', true),
            ],
            'lignes.*.montants_standard' => ['nullable', 'array'],
            'lignes.*.exceptions' => ['nullable', 'array'],
            'lignes.*.exceptions.*.type_vehicule_id' => [
                'required',
                'string',
                'distinct',
                Rule::exists('type_vehicules', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('is_active', true),
            ],
            'lignes.*.exceptions.*.montants' => ['nullable', 'array'],
        ];
    }

    /**
     * Règles transverses impossibles à exprimer avec des chemins déclaratifs
     * classiques (dépendance entre `beneficiaires` et les clés dynamiques de
     * `montants_standard`/`exceptions.*.montants`) :
     * - consultant_id requis seulement si `consultant` est coché ;
     * - un montant entier strictement positif (1–99 999 999) pour chaque
     *   bénéficiaire coché, ni plus ni moins ;
     * - les montants d'exception ne peuvent porter que sur des bénéficiaires cochés.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            foreach ($this->input('lignes', []) as $index => $ligne) {
                $beneficiaires = is_array($ligne['beneficiaires'] ?? null) ? $ligne['beneficiaires'] : [];
                $montantsStandard = is_array($ligne['montants_standard'] ?? null) ? $ligne['montants_standard'] : [];

                if (in_array(CommissionCibleType::CODE_CONSULTANT, $beneficiaires, true) && empty($ligne['consultant_id'])) {
                    $validator->errors()->add(
                        "lignes.{$index}.consultant_id",
                        'Choisissez le consultant bénéficiaire de cette catégorie.',
                    );
                }

                foreach ($beneficiaires as $cibleType) {
                    if (! in_array($cibleType, self::CIBLE_CODES, true)) {
                        continue;
                    }
                    $this->validerMontant(
                        $validator,
                        $montantsStandard[$cibleType] ?? null,
                        "lignes.{$index}.montants_standard.{$cibleType}",
                    );
                }

                foreach ($montantsStandard as $cibleType => $montant) {
                    if (! in_array($cibleType, $beneficiaires, true)) {
                        $validator->errors()->add(
                            "lignes.{$index}.montants_standard.{$cibleType}",
                            'Ce bénéficiaire n’est pas coché.',
                        );
                    }
                }

                $exceptions = is_array($ligne['exceptions'] ?? null) ? $ligne['exceptions'] : [];
                foreach ($exceptions as $exceptionIndex => $exception) {
                    $montants = is_array($exception['montants'] ?? null) ? $exception['montants'] : [];

                    foreach ($montants as $cibleType => $montant) {
                        if (! in_array($cibleType, $beneficiaires, true)) {
                            $validator->errors()->add(
                                "lignes.{$index}.exceptions.{$exceptionIndex}.montants.{$cibleType}",
                                'Ce bénéficiaire n’est pas coché.',
                            );

                            continue;
                        }
                        $this->validerMontant(
                            $validator,
                            $montant,
                            "lignes.{$index}.exceptions.{$exceptionIndex}.montants.{$cibleType}",
                        );
                    }
                }
            }
        });
    }

    private function validerMontant(ValidatorContract $validator, mixed $montant, string $attribut): void
    {
        if ($montant === null || $montant === '') {
            $validator->errors()->add($attribut, 'Saisissez un montant entier, supérieur à 0.');

            return;
        }

        if (! preg_match('/^\d+$/', (string) $montant) || (int) $montant < 1 || (int) $montant > 99_999_999) {
            $validator->errors()->add($attribut, 'Saisissez un montant entier, supérieur à 0.');
        }
    }

    public function messages(): array
    {
        return [
            'lignes.required' => 'Ajoutez au moins une catégorie autorisée.',
            'lignes.min' => 'Ajoutez au moins une catégorie autorisée.',
            'lignes.*.categorie_id.required' => 'Choisissez une catégorie.',
            'lignes.*.categorie_id.distinct' => 'Une catégorie ne peut être ajoutée qu’une seule fois.',
            'lignes.*.categorie_id.exists' => 'Cette catégorie n’est pas disponible pour votre organisation.',
            'lignes.*.beneficiaires.required' => 'Cochez au moins un bénéficiaire.',
            'lignes.*.beneficiaires.min' => 'Cochez au moins un bénéficiaire.',
            'lignes.*.consultant_id.exists' => 'Ce consultant doit être actif et appartenir à votre organisation.',
            'lignes.*.exceptions.*.type_vehicule_id.required' => 'Choisissez un type de véhicule.',
            'lignes.*.exceptions.*.type_vehicule_id.distinct' => 'Ce type de véhicule a déjà une exception sur cette catégorie.',
            'lignes.*.exceptions.*.type_vehicule_id.exists' => 'Ce type de véhicule n’est pas disponible pour votre organisation.',
        ];
    }
}
