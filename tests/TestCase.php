<?php

namespace Tests;

use App\Support\Database\TransactionAnnulee;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        // La transaction d'enveloppe de RefreshDatabase n'est pas une opération métier : la garde
        // des transactions annulées s'applique au-dessus (cf. App\Support\Database\TransactionAnnulee).
        $traits = class_uses_recursive(static::class);
        TransactionAnnulee::$niveauRacine = isset($traits[RefreshDatabase::class]) || isset($traits[DatabaseTransactions::class]) ? 1 : 0;
    }

    /**
     * Le garde-fou s'exécute ici, juste après la création de l'application et AVANT
     * setUpTraits() : c'est setUpTraits() qui lance RefreshDatabase (migrate:fresh). Placé
     * après parent::setUp(), il ne levait son exception qu'une fois la base déjà vidée
     * (incident du 06/10/2026 : config mise en cache sur MySQL → `elm-monolithe` effacée).
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();
        $this->guardAgainstRealDatabase();
    }

    /**
     * Garde-fou dur : un test ne doit jamais pouvoir toucher la vraie base MySQL de dev
     * (RefreshDatabase y ferait un DROP/CREATE en conditions réelles). phpunit.xml force
     * déjà DB_CONNECTION=sqlite / DB_DATABASE=:memory: via l'attribut force="true", mais
     * bootstrap/cache/config.php (config:cache / optimize) l'emporte sur ces variables :
     * toute dérive de config fait échouer le test avant la moindre requête.
     */
    private function guardAgainstRealDatabase(): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = config("database.connections.{$connection}.database");

        if ($driver !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Configuration de test invalide : connexion '{$connection}' (driver: {$driver}, base: {$database}). ".
                'Les tests doivent obligatoirement tourner sur sqlite :memory: — jamais sur une base MySQL réelle. '.
                'Vérifie phpunit.xml / .env.testing / APP_ENV avant de relancer.'
            );
        }
    }
}
