<?php

namespace App\Http\Requests\Settings;

use App\Services\ThemePolicyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Seul point d'entrée qui écrit le thème global — la validation contre la
 * politique de l'environnement (ThemePolicyService) est ici l'autorité, le
 * frontend ne masquant les valeurs interdites que pour l'UX (jamais pour la
 * sécurité, cf. docs/theming.md).
 */
class UpdateThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('parametres.update') ?? false;
    }

    public function rules(): array
    {
        $policy = app(ThemePolicyService::class);

        return [
            'preset' => ['required', 'string', Rule::in($policy->allowedPresets())],
            'primary' => ['required', 'string', Rule::in($policy->allowedPrimaries())],
            'surface' => ['required', 'string', Rule::in($policy->allowedSurfaces())],
        ];
    }
}
