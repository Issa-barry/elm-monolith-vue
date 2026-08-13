<?php

namespace App\Exceptions\Comptabilite;

use RuntimeException;

class PeriodeComptableClotureeException extends RuntimeException
{
    public static function pourDate(string $organizationId, string $date): self
    {
        return new self("La période comptable couvrant le {$date} (organisation {$organizationId}) est clôturée. Aucune pièce ordinaire ne peut y être postée.");
    }
}
