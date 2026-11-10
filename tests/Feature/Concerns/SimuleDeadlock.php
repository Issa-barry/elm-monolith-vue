<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deadlock simulé sous SQLite : une vraie requête qui échoue avec le message de MySQL, donc vue par
 * la connexion comme un deadlock (cf. App\Support\Database\TransactionAnnulee).
 */
trait SimuleDeadlock
{
    private function preparerDeadlockSimule(): void
    {
        DB::statement('CREATE TEMP TABLE IF NOT EXISTS simulation_deadlock (x INTEGER)');
        DB::statement("CREATE TEMP TRIGGER IF NOT EXISTS simulation_deadlock_declencheur BEFORE INSERT ON simulation_deadlock BEGIN SELECT RAISE(ABORT, 'Deadlock found when trying to get lock; try restarting transaction'); END");
    }

    private function requeteEnDeadlock(): void
    {
        DB::insert('INSERT INTO simulation_deadlock (x) VALUES (1)');
    }

    /** Un catch qui avale l'erreur, comme les blocs « ne doit jamais faire échouer l'opération ». */
    private function deadlockMasque(): void
    {
        try {
            $this->requeteEnDeadlock();
        } catch (Throwable) {
            // erreur volontairement masquée
        }
    }
}
