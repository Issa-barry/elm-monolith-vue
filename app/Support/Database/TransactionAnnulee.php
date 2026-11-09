<?php

namespace App\Support\Database;

use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\LostConnectionDetector;
use Throwable;

/**
 * Transaction perdue côté base : sur un deadlock (ou une connexion perdue), MySQL annule TOUTE la
 * transaction, pas seulement la requête fautive. Une erreur masquée par un `catch` laissait le code
 * continuer hors transaction : chaque écriture suivante était validée aussitôt, sans ce qui la
 * précédait (incident VTE-081126-134 du 08/11/2026 : pièce de caisse et audit conservés,
 * encaissement disparu). Cf. RefuseRequetesApresTransactionAnnulee.
 */
final class TransactionAnnulee
{
    /**
     * Niveau de transaction qui n'appartient à aucune opération métier : 0 en production, 1 sous
     * RefreshDatabase (transaction d'enveloppe des tests, cf. Tests\TestCase).
     */
    public static int $niveauRacine = 0;

    public static function estCompromise(Throwable $e): bool
    {
        return $e instanceof DeadlockException
            || (new ConcurrencyErrorDetector)->causedByConcurrencyError($e)
            || (new LostConnectionDetector)->causedByLostConnection($e);
    }
}
