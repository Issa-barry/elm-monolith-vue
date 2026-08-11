<?php

namespace Tests\Concerns;

use Illuminate\Testing\PendingCommand;

trait HasInstallAppHelper
{
    protected function runInstall(
        string $orgNom = 'ELM Test',
        string $slug = 'elm-test',
        string $telephone = '+224622000000',
        string $password = 'Sup3r$ecretPwd',
        bool $donneesParDefaut = false,
        ?int $preset = null,
    ): PendingCommand {
        $command = $this->artisan('app:install')
            ->expectsQuestion("Nom de l'organisation", $orgNom)
            ->expectsQuestion('Slug', $slug)
            ->expectsQuestion('Prénom', 'Issa')
            ->expectsQuestion('Nom', 'BARRY')
            ->expectsQuestion('Téléphone (format international, ex: +224622000000)', $telephone)
            ->expectsQuestion('Email (facultatif)', '')
            ->expectsQuestion('Mot de passe (min. 8 caractères, majuscule + minuscule + symbole)', $password)
            ->expectsQuestion('Confirmer le mot de passe', $password)
            ->expectsConfirmation(
                'Voulez-vous installer les données par défaut (catégories, options) ?',
                $preset !== null || $donneesParDefaut ? 'yes' : 'no'
            );

        if ($preset !== null) {
            $command->expectsChoice(
                'Choisissez un modèle',
                $preset,
                [
                    1 => 'Distribution d\'eau (Boissons > Eau / Sachet / Bouteille)',
                    2 => 'Commerce / POS (catalogue générique : vêtements, chaussures, boissons...)',
                    3 => 'Installation minimale (aucune donnée catégorie/option)',
                ]
            );
        }

        return $command;
    }
}
