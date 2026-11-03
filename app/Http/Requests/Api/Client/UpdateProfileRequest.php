<?php

namespace App\Http\Requests\Api\Client;

use App\Models\UserAuthIdentity;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Champs modifiables par le titulaire du compte lui-même (ADR 0051) : prénom, nom,
 * e-mail (appliqué après confirmation par lien) et localisation. Le pays est un
 * libellé choisi dans une liste fermée. Volontairement exclus : telephone (identifiant
 * de connexion), code_pays/code_phone_pays (recomposent le téléphone dans les
 * formulaires du back-office), raison_sociale/type (identité légale) et is_active.
 */
class UpdateProfileRequest extends FormRequest
{
    use PhoneHandlerTrait;

    /** @return list<string> */
    public static function paysDisponibles(): array
    {
        return array_column(static::supportedPays(), 0);
    }

    /**
     * Mêmes pays avec leur code ISO, pour afficher le drapeau sans recopier la liste côté frontend.
     *
     * @return list<array{label: string, code: string}>
     */
    public static function paysOptions(): array
    {
        return array_map(
            fn (string $code, array $pays): array => ['label' => $pays[0], 'code' => $code],
            array_keys(static::supportedPays()),
            array_values(static::supportedPays()),
        );
    }

    protected function prepareForValidation(): void
    {
        // Une valeur mal formée (tableau) n'est pas normalisée : les règles la refusent en 422.
        $texte = fn (string $champ): ?string => is_string($this->input($champ)) && filled($this->input($champ))
            ? $this->input($champ)
            : null;

        $normalises = [];

        if ($prenom = $texte('prenom')) {
            $normalises['prenom'] = mb_convert_case(mb_strtolower($prenom, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }

        if ($nom = $texte('nom')) {
            $normalises['nom'] = mb_strtoupper($nom, 'UTF-8');
        }

        if ($email = $texte('email')) {
            $normalises['email'] = UserAuthIdentity::normaliser(UserAuthIdentity::TYPE_EMAIL, $email);
        }

        $this->merge($normalises);
    }

    public function rules(): array
    {
        return [
            'prenom' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'nom' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'email' => [
                'bail',
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                function ($attribute, $value, $fail) {
                    $existant = UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_EMAIL, $value);
                    if ($existant && $existant->id !== $this->user()->id) {
                        $fail('Cette adresse e-mail est déjà utilisée.');
                    }
                },
            ],
            'pays' => ['nullable', 'string', Rule::in(self::paysDisponibles())],
            'ville' => ['nullable', 'string', 'max:100'],
            'adresse' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'prenom.required' => 'Le prénom est obligatoire.',
            'prenom.min' => 'Le prénom doit contenir au moins 2 caractères.',
            'prenom.max' => 'Le prénom ne doit pas dépasser 100 caractères.',
            'nom.required' => 'Le nom est obligatoire.',
            'nom.min' => 'Le nom doit contenir au moins 2 caractères.',
            'nom.max' => 'Le nom ne doit pas dépasser 100 caractères.',
            'email.required' => 'Saisissez une adresse e-mail.',
            'email.email' => 'Cette adresse e-mail n\'est pas valide.',
            'email.max' => 'L\'adresse e-mail ne doit pas dépasser 255 caractères.',
            'pays.in' => 'Sélectionnez un pays dans la liste.',
        ];
    }
}
