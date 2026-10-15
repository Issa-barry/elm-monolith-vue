<?php

/**
 * Sous-processus de Tests\Feature\Tresorerie\CompteMobileMoneyAgentConcurrenceTest : une création de
 * compte Mobile Money d'agent sur SA propre connexion MySQL, déclenchée au même instant que les
 * autres (fichier « go »). Jamais lancé à la main : il écrit dans la base `mysql_testing`.
 *
 * Arguments : organisation, agence, agent, numéro, dossier de synchronisation, nom du processus.
 */

use App\Services\Tresorerie\CaisseAgentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

[, $organizationId, $siteId, $agentId, $numero, $dossier, $nom] = $argv;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['database.default' => 'mysql_testing']);

try {
    DB::connection()->getPdo();
    file_put_contents("{$dossier}/{$nom}_pret", '1');

    $limite = microtime(true) + 90;
    while (! file_exists("{$dossier}/go") && microtime(true) < $limite) {
        usleep(1000);
    }

    $compte = app(CaisseAgentService::class)->creerCompteMobileMoney($organizationId, $siteId, $agentId, 'orange_money', $numero);
    $resultat = ['ok' => true, 'compte' => $compte->compte->numero, 'numero' => $compte->numero];
} catch (ValidationException $e) {
    $resultat = ['ok' => false, 'erreurs' => $e->errors()];
} catch (Throwable $e) {
    $resultat = ['ok' => false, 'exception' => $e::class.' : '.$e->getMessage()];
}

file_put_contents("{$dossier}/{$nom}_fini", json_encode($resultat));
