<?php

namespace App\Jobs;

use App\Enums\StatutImportFlotte;
use App\Models\ImportFlotte;
use App\Services\ImportFlotte\ImportFlotteExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Traité par une tâche Cron (`queue:work --stop-when-empty`) sur l'hébergement
 * mutualisé Hostinger — pas de worker permanent Supervisor/systemd. Voir
 * DEPLOY-HOSTINGER-CICD.md pour la commande cron exacte.
 *
 * Ne catch PAS les exceptions imprévues (ex: coupure DB, deadlock) : elles
 * doivent remonter pour que Laravel retente ($tries) puis appelle failed()
 * si les tentatives sont épuisées. Les erreurs de validation métier, elles,
 * ne sont jamais des exceptions — ImportFlotteExecutor::executer() les
 * retourne sous forme de données (succes: false), traitées normalement ici.
 */
class ProcessImportFlotteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly string $importId) {}

    public function handle(ImportFlotteExecutor $executor): void
    {
        $import = ImportFlotte::find($this->importId);
        if (! $import) {
            return;
        }

        $import->update([
            'statut' => StatutImportFlotte::EN_COURS->value,
            'demarre_le' => $import->demarre_le ?? now(),
        ]);

        $resultat = $executor->executer($import);
        $groupes = $resultat['rapport']['groupes'];
        $nbErreur = count(array_filter($groupes, fn ($g) => $g['statut'] === 'erreur'));

        if ($resultat['succes']) {
            $import->update([
                'statut' => StatutImportFlotte::TERMINE->value,
                'rapport' => $resultat['rapport'],
                'nb_groupes_valides' => count($groupes),
                'nb_groupes_erreur' => 0,
                'nb_proprietaires_crees' => $resultat['compteurs']['proprietaires_crees'],
                'nb_vehicules_crees' => $resultat['compteurs']['vehicules_crees'],
                'nb_livreurs_crees' => $resultat['compteurs']['livreurs_crees'],
                'nb_equipes_creees' => $resultat['compteurs']['equipes_creees'],
                'termine_le' => now(),
            ]);
        } else {
            $import->update([
                'statut' => StatutImportFlotte::ECHOUE->value,
                'rapport' => $resultat['rapport'],
                'nb_groupes_valides' => count($groupes) - $nbErreur,
                'nb_groupes_erreur' => $nbErreur,
                'termine_le' => now(),
            ]);
        }
    }

    /**
     * Appelé par Laravel une fois les $tries tentatives épuisées (ou immédiatement
     * sur le driver "sync"). C'est le seul endroit qui doit marquer l'import
     * "echoue" suite à une exception réelle — pas handle(), pour laisser jouer
     * le mécanisme de retry entre chaque tentative.
     */
    public function failed(Throwable $exception): void
    {
        report($exception);

        $import = ImportFlotte::find($this->importId);
        $import?->update([
            'statut' => StatutImportFlotte::ECHOUE->value,
            'rapport' => ['erreur_fatale' => $exception->getMessage()],
            'termine_le' => now(),
        ]);
    }
}
