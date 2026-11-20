<?php

/**
 * Sous-processus de Tests\Feature\Comptabilite\PaiementFicheConcurrenceTest : UNE requête de paiement
 * (bouton Payer ou paiement groupé) passée par le noyau HTTP, comme depuis un navigateur, sur SA
 * propre connexion MySQL, déclenchée au même instant que les autres (fichier « go »). Jamais lancé à
 * la main : il écrit dans la base `mysql_testing`.
 *
 * Arguments : dossier de synchronisation, nom du processus. L'ordre est lu dans {nom}_ordre.json.
 */

use App\Models\PaiementFichePaiement;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

[, $dossier, $nom] = $argv;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
// Le générateur d'URL exige une requête dès l'amorçage ; la vraie requête est passée plus bas.
$app->instance('request', Request::create('/'));
$kernel->bootstrap();

config(['database.default' => 'mysql_testing']);

try {
    $ordre = json_decode((string) file_get_contents("{$dossier}/{$nom}_ordre.json"), true, flags: JSON_THROW_ON_ERROR);
    DB::connection()->getPdo();
    ValidateCsrfToken::except(['*']);
    Auth::guard('web')->setUser(User::findOrFail($ordre['user_id']));

    // Allonge la transaction juste avant l'écriture du paiement, contrôles déjà faits : sans verrou,
    // l'autre processus passerait ses propres contrôles sur un état périmé et paierait aussi.
    PaiementFichePaiement::creating(fn () => usleep($ordre['delai_ms'] * 1000));

    $serveur = ['HTTP_ACCEPT' => $ordre['json'] ? 'application/json' : 'text/html', 'CONTENT_TYPE' => 'application/json'];
    foreach ($ordre['entetes'] as $entete => $valeur) {
        $serveur['HTTP_'.strtoupper(str_replace('-', '_', $entete))] = $valeur;
    }
    $request = Request::create($ordre['uri'], 'POST', [], [], [], $serveur, json_encode($ordre['corps']));

    file_put_contents("{$dossier}/{$nom}_pret", '1');
    $limite = microtime(true) + 180;
    while (! file_exists("{$dossier}/go") && microtime(true) < $limite) {
        usleep(1000);
    }

    $debut = microtime(true);
    $response = $kernel->handle($request);
    $fin = microtime(true);
    $session = $request->hasSession() ? $request->session() : null;
    $contenu = (string) $response->getContent();

    $resultat = [
        'statut' => $response->getStatusCode(),
        'json' => json_decode($contenu, true),
        'erreurs' => $session?->get('errors')?->getBag('default')->toArray() ?? [],
        'succes' => $session?->get('success'),
        // Un refus métier (ValidationException) est une réponse normale, pas une erreur du processus.
        'exception' => $response->exception !== null && ! $response->exception instanceof ValidationException
            ? $response->exception::class.' : '.$response->exception->getMessage()
            : ($response->getStatusCode() >= 500 ? 'Erreur '.$response->getStatusCode() : null),
        'debut' => $debut,
        'fin' => $fin,
    ];
} catch (Throwable $e) {
    $resultat = ['statut' => null, 'exception' => $e::class.' : '.$e->getMessage()];
}

file_put_contents("{$dossier}/{$nom}_fini", json_encode($resultat, JSON_UNESCAPED_UNICODE));
