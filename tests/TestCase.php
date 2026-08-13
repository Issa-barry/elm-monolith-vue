<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->guardAgainstRealDatabase();
    }

    /**
     * Garde-fou dur : un test ne doit jamais pouvoir toucher la vraie base MySQL de dev
     * (RefreshDatabase y ferait un DROP/CREATE en conditions réelles). phpunit.xml force
     * déjà DB_CONNECTION=sqlite / DB_DATABASE=:memory: via l'attribut force="true", mais on
     * revérifie aussi ici à l'exécution : toute dérive de config (APP_ENV mal chargé,
     * .env.testing modifié, override local) fait échouer le test immédiatement au lieu de
     * risquer de vider une base réelle.
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
