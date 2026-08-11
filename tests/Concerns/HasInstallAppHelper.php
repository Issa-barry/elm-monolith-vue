<?php

namespace Tests\Concerns;

use Illuminate\Testing\PendingCommand;

trait HasInstallAppHelper
{
    protected function runInstall(
        string $orgNom = 'ELM Test',
        string $telephone = '+224622000000',
        string $password = 'Sup3r$ecretPwd',
        bool $categories = false,
        bool $options = false,
        bool $typesVehicule = false,
    ): PendingCommand {
        return $this->artisan('app:install')
            ->expectsQuestion("Nom de l'entreprise", $orgNom)
            ->expectsQuestion('Prénom', 'Issa')
            ->expectsQuestion('Nom', 'BARRY')
            ->expectsQuestion('Téléphone (format international, ex: +224622000000)', $telephone)
            ->expectsQuestion('Email (facultatif)', '')
            ->expectsQuestion('Mot de passe (min. 8 caractères, majuscule + minuscule + symbole)', $password)
            ->expectsQuestion('Confirmer le mot de passe', $password)
            ->expectsConfirmation('Créer les catégories prédéfinies ?', $categories ? 'yes' : 'no')
            ->expectsConfirmation('Installer la bibliothèque d\'options prédéfinies ?', $options ? 'yes' : 'no')
            ->expectsConfirmation(
                'Créer les types de véhicule prédéfinis (Tricycle, Minibus, Camionnette, Camion, Remorque) ?',
                $typesVehicule ? 'yes' : 'no',
            );
    }
}
