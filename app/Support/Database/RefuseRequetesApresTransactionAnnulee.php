<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Garde de connexion : une fois la transaction perdue (deadlock, délai de verrou, connexion perdue),
 * toute requête suivante est refusée jusqu'à la fin de l'opération, et la validation finale échoue.
 * Un `catch` qui masque l'erreur ne peut donc plus faire valider à moitié une opération : sans cette
 * garde, les écritures suivantes partaient hors transaction et restaient en base seules.
 *
 * @mixin Connection
 */
trait RefuseRequetesApresTransactionAnnulee
{
    private ?string $motifTransactionAnnulee = null;

    public function beginTransaction()
    {
        if ($this->transactions <= TransactionAnnulee::$niveauRacine) {
            $this->motifTransactionAnnulee = null;
        }

        parent::beginTransaction();
    }

    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        if ($this->motifTransactionAnnulee !== null) {
            if ($this->transactions > TransactionAnnulee::$niveauRacine) {
                throw TransactionAnnuleeException::apres($this->motifTransactionAnnulee);
            }
            $this->motifTransactionAnnulee = null;
        }

        try {
            return parent::runQueryCallback($query, $bindings, $callback);
        } catch (QueryException $e) {
            if ($this->transactions > TransactionAnnulee::$niveauRacine && TransactionAnnulee::estCompromise($e)) {
                $this->motifTransactionAnnulee = $e->getMessage();
            }

            throw $e;
        }
    }

    /**
     * Dernier rempart, quand toutes les erreurs ont été masquées : la validation de la transaction
     * principale échoue au lieu de valider une transaction que la base a déjà annulée.
     */
    protected function fireConnectionEvent($event)
    {
        if ($event === 'committing' && $this->motifTransactionAnnulee !== null) {
            $motif = $this->motifTransactionAnnulee;
            $this->motifTransactionAnnulee = null;

            $pdo = $this->getPdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->transactionsManager?->rollback($this->getName(), 0);

            throw TransactionAnnuleeException::apres($motif);
        }

        return parent::fireConnectionEvent($event);
    }
}
