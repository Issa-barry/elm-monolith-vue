<?php

namespace App\Support\Database;

use Illuminate\Database\DeadlockException;

/**
 * Requête refusée parce que la base a déjà annulé la transaction en cours. Code SQLSTATE 40001 :
 * Laravel la traite comme un deadlock (remontée sans savepoint, nouvelle tentative si l'appelant
 * en a prévu), et tout `catch` qui relance les erreurs de concurrence la laisse passer.
 */
final class TransactionAnnuleeException extends DeadlockException
{
    public static function apres(string $motif): self
    {
        return new self(
            'Transaction annulée par la base de données, aucune écriture n\'est plus acceptée avant la fin de l\'opération. Cause : '
                .mb_substr($motif, 0, 300),
            40001,
        );
    }
}
