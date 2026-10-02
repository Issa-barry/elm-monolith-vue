<?php

namespace App\Exceptions\Tresorerie;

use RuntimeException;

class SiteCentralTresorerieIndisponibleException extends RuntimeException
{
    public static function pourOrganisation(string $organizationId): self
    {
        return new self(
            "Aucun site n'est désigné comme trésorerie principale pour l'organisation {$organizationId}. ".
            "Un administrateur doit désigner la trésorerie principale avant d'utiliser la trésorerie."
        );
    }
}
