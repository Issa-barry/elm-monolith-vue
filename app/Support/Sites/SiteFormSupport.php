<?php

namespace App\Support\Sites;

/**
 * Normalisation et messages de validation partagés entre la création et la modification d'un
 * site — extrait de SiteController::normalizeStrings()/messages().
 */
final class SiteFormSupport
{
    public static function normalizeStrings(array $data): array
    {
        if (! empty($data['code'])) {
            $data['code'] = mb_strtoupper(trim($data['code']), 'UTF-8');
        }
        if (! empty($data['nom'])) {
            $data['nom'] = mb_convert_case(mb_strtolower($data['nom']), MB_CASE_TITLE, 'UTF-8');
        }
        if (! empty($data['ville'])) {
            $data['ville'] = mb_convert_case(mb_strtolower($data['ville']), MB_CASE_TITLE, 'UTF-8');
        }
        if (! empty($data['quartier'])) {
            $data['quartier'] = mb_convert_case(mb_strtolower($data['quartier']), MB_CASE_TITLE, 'UTF-8');
        }

        return $data;
    }

    public static function messages(): array
    {
        return [
            'nom.required' => 'Le nom du site est obligatoire.',
            'nom.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'code.required' => 'Le code du site est obligatoire.',
            'code.max' => 'Le code ne peut pas dépasser 50 caractères.',
            'code.regex' => 'Le code ne peut contenir que des majuscules, chiffres, tirets et underscores.',
            'code.unique' => 'Ce code est déjà utilisé par un autre site de votre organisation.',
            'type.required' => 'Le type de site est obligatoire.',
            'type.in' => 'Type de site invalide.',
            'statut.in' => 'Statut invalide.',
            'localisation.required' => "L'adresse du site est obligatoire.",
            'parent_id.exists' => 'Le site parent sélectionné est introuvable.',
        ];
    }
}
