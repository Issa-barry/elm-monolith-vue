<?php

namespace Tests\Concerns;

use App\Enums\DomaineActivite;
use Illuminate\Testing\PendingCommand;

trait HasInstallAppHelper
{
    protected function runInstall(
        string $orgNom = 'ELM Test',
        string $telephone = '+224622000000',
        string $password = 'Sup3r$ecretPwd',
        DomaineActivite $domaine = DomaineActivite::COMMERCE_DISTRIBUTION,
    ): PendingCommand {
        return $this->artisan('app:install')
            ->expectsQuestion("Nom de l'entreprise", $orgNom)
            ->expectsQuestion("Domaine d'activité de l'entreprise", $domaine->label())
            ->expectsQuestion('Prénom', 'Issa')
            ->expectsQuestion('Nom', 'BARRY')
            ->expectsQuestion('Téléphone (format international, ex: +224622000000)', $telephone)
            ->expectsQuestion('Email (facultatif)', '')
            ->expectsQuestion('Mot de passe (min. 8 caractères, majuscule + minuscule + symbole)', $password)
            ->expectsQuestion('Confirmer le mot de passe', $password);
    }
}
