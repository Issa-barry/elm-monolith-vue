<?php

/**
 * Sous-processus de Tests\Feature\Tresorerie\SoldeSupportConcurrenceTest : UNE sortie d'argent d'un
 * support, comme la font les sept appelants de TresorerieDisponibiliteService::garantirSoldeSuffisant()
 * (contrôle du solde sous verrou du support, puis écriture de la sortie), sur sa propre connexion
 * MySQL, déclenchée au même instant que les autres (fichier « go »). Jamais lancé à la main : il
 * écrit dans la base `mysql_testing`.
 *
 * Arguments : dossier de synchronisation, nom du processus. L'ordre est lu dans {nom}_ordre.json.
 */

use App\Enums\EvenementComptable;
use App\Models\User;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

[, $dossier, $nom] = $argv;
$lancement = microtime(true);

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['database.default' => 'mysql_testing']);

try {
    $ordre = json_decode((string) file_get_contents("{$dossier}/{$nom}_ordre.json"), true, flags: JSON_THROW_ON_ERROR);
    DB::connection()->getPdo();
    $auteur = User::findOrFail($ordre['user_id']);

    file_put_contents("{$dossier}/{$nom}_pret", json_encode(['demarrage' => microtime(true) - $lancement]));
    $limite = microtime(true) + 180;
    while (! file_exists("{$dossier}/go") && microtime(true) < $limite) {
        usleep(1000);
    }

    $debut = microtime(true);
    $resultat = ['statut' => 'ok', 'refus' => null];
    try {
        DB::transaction(function () use ($ordre, $auteur) {
            // Lecture simple AVANT d'attendre le verrou du support, comme le font la plupart des
            // appelants (fiche, facture, mouvement relus) : elle fige la vue REPEATABLE READ.
            DB::table('compta_supports_tresorerie')->where('id', $ordre['support_id'])->value('libelle');

            $support = app(TresorerieDisponibiliteService::class)
                ->garantirSoldeSuffisant($ordre['support_id'], (float) $ordre['montant'], now(), 'une sortie');

            app(EcritureComptableService::class)->comptabiliser(
                evenement: EvenementComptable::SOLDE_OUVERTURE_TRESORERIE,
                source: $auteur,
                organizationId: $support->organization_id,
                dateComptable: Carbon::now(),
                libelle: "Sortie concurrente {$auteur->id}",
                lignes: [
                    ['compte_comptable_id' => $support->compte_comptable_id, 'sens' => 'credit', 'montant' => (float) $ordre['montant']],
                    ['role' => 'contrepartie_ouverture', 'sens' => 'debit', 'montant' => (float) $ordre['montant']],
                ],
                siteId: $support->site_id,
                createdBy: $auteur->id,
            );

            // Verrous tenus le temps que l'autre processus arrive sur le même support.
            usleep($ordre['delai_ms'] * 1000);
        });
    } catch (ValidationException $e) {
        $resultat = ['statut' => 'refus', 'refus' => $e->errors()['montant'][0] ?? $e->getMessage()];
    }

    $resultat += ['exception' => null, 'debut' => $debut, 'fin' => microtime(true)];
} catch (Throwable $e) {
    $resultat = ['statut' => null, 'exception' => $e::class.' : '.$e->getMessage()];
}

file_put_contents("{$dossier}/{$nom}_fini", json_encode($resultat, JSON_UNESCAPED_UNICODE));
