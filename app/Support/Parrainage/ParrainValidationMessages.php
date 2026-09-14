<?php

namespace App\Support\Parrainage;

/**
 * Messages de validation partagés entre la création et la modification d'un parrain — extrait de
 * ParrainController::validationMessages().
 */
final class ParrainValidationMessages
{
    public static function pour(): array
    {
        return [
            'nom_complet.required' => 'Le nom complet est obligatoire.',
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.required' => 'Le pays est obligatoire.',
            'code_pays.in' => 'Pays invalide.',
        ];
    }
}
