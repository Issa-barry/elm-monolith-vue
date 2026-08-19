<?php

namespace Tests\Concerns;

use App\Enums\DomaineActivite;
use App\Enums\SiteType;
use Illuminate\Testing\PendingCommand;

trait HasInstallAppHelper
{
    /**
     * on_premise (mode par défaut, cf. config/app.php) : l'email est obligatoire, donc le prompt
     * CLI devient "Email" (pas "Email (facultatif)") et enchaîne sur la vérification par code —
     * OTP_FIXED_CODE=123456 dans .env.testing rend ce code déterministe (cf. config/otp.php).
     *
     * Site : "Siège" est toujours la première suggestion proposée par askSite() (cf.
     * DomaineActivite::siteTypes(), qui liste SIEGE en premier pour tous les domaines), d'où le
     * choix par défaut ci-dessous.
     */
    protected function runInstall(
        string $orgNom = 'ELM Test',
        string $telephone = '+224622000000',
        string $password = 'Sup3r$ecretPwd',
        DomaineActivite $domaine = DomaineActivite::COMMERCE_DISTRIBUTION,
        string $email = 'issa@gmail.com',
        SiteType $siteType = SiteType::SIEGE,
        string $siteVille = 'Conakry',
        string $siteQuartier = 'Matoto',
    ): PendingCommand {
        return $this->artisan('app:install')
            ->expectsQuestion("Nom de l'entreprise", $orgNom)
            ->expectsQuestion("Domaine d'activité de l'entreprise", $domaine->label())
            ->expectsQuestion('Prénom', 'Issa')
            ->expectsQuestion('Nom', 'BARRY')
            ->expectsQuestion('Téléphone (format international, ex: +224622000000)', $telephone)
            ->expectsQuestion('Email', $email)
            ->expectsQuestion('Code reçu par email (6 chiffres)', '123456')
            ->expectsQuestion('Mot de passe (min. 8 caractères, majuscule + minuscule + symbole)', $password)
            ->expectsQuestion('Type de site', $siteType->label())
            ->expectsQuestion('Ville', $siteVille)
            ->expectsQuestion('Quartier', $siteQuartier);
    }
}
