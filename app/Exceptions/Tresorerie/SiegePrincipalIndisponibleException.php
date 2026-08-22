<?php

namespace App\Exceptions\Tresorerie;

use RuntimeException;

class SiegePrincipalIndisponibleException extends RuntimeException
{
    public static function pourOrganisation(string $organizationId): self
    {
        return new self(
            "Aucun site siège principal n'est configuré pour l'organisation {$organizationId}. ".
            "Un administrateur doit marquer un site de type « siège » comme principal avant d'utiliser la trésorerie."
        );
    }
}
