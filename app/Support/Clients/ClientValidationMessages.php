<?php

namespace App\Support\Clients;

/**
 * Messages de validation partagés entre la création et la modification d'un client — extrait de
 * ClientController::validationMessages().
 */
final class ClientValidationMessages
{
    public static function pour(): array
    {
        return [
            'nom_complet.required' => 'Le nom complet est obligatoire.',
            'email.email' => "L'adresse email est invalide.",
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.required' => 'Le pays est obligatoire.',
            'code_pays.in' => 'Pays invalide.',
        ];
    }
}
