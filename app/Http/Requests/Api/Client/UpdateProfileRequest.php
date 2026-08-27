<?php

namespace App\Http\Requests\Api\Client;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Champs modifiables par le titulaire du compte lui-même — uniquement la
 * localisation. Volontairement exclus (décision du 26/08/2026, cf. rapport) :
 * nom/prenom (identité civile), telephone/email (identifiants de connexion,
 * unicité par organisation à revalider), raison_sociale/type (identité légale)
 * et is_active (jamais en self-service) — ces champs restent réservés au
 * backoffice, cohérent avec la séparation déjà en place partout ailleurs dans
 * l'application entre édition "propriétaire" (self-service) et édition "staff"
 * (ProprietaireController, ClientController...).
 */
class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'pays' => ['nullable', 'string', 'max:100'],
            'code_pays' => ['nullable', 'string', 'max:10'],
            'ville' => ['nullable', 'string', 'max:100'],
            'adresse' => ['nullable', 'string', 'max:255'],
        ];
    }
}
