<?php

namespace App\Exceptions\Comptabilite;

use RuntimeException;

class EcritureDesequilibreeException extends RuntimeException
{
    public static function pourEcart(float $totalDebit, float $totalCredit): self
    {
        return new self(sprintf(
            'Pièce comptable déséquilibrée : total débit %.2f ≠ total crédit %.2f.',
            $totalDebit,
            $totalCredit
        ));
    }

    public static function pourLigneInvalide(int $index, float $debit, float $credit): self
    {
        return new self(sprintf(
            'Ligne %d invalide : debit=%.2f, credit=%.2f (une seule valeur doit être > 0).',
            $index,
            $debit,
            $credit
        ));
    }
}
