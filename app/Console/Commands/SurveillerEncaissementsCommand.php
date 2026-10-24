<?php

namespace App\Console\Commands;

use App\Models\ControleEncaissements;
use App\Services\Ventes\ControleEncaissements\AlertesAnomaliesEncaissement;
use App\Services\Ventes\ControleEncaissements\SurveillanceEncaissements;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Contrôle périodique des encaissements (ADR 0036), planifié toutes les heures
 * (routes/console.php) : rescane toutes les factures, enregistre les anomalies nouvelles, note les
 * écarts disparus, relance les anomalies non traitées et envoie les e-mails en attente ou en échec.
 * Idempotent : une anomalie déjà connue n'est ni recréée ni renotifiée.
 *
 * Ne corrige jamais une donnée financière.
 */
class SurveillerEncaissementsCommand extends Command
{
    protected $signature = 'encaissements:surveiller
        {--organization=* : ID d\'organisation (répétable) ; toutes si omis}
        {--source=commande : planificateur | commande}';

    protected $description = 'Contrôle des encaissements : détecte doublons, surplus et incohérences, alerte les responsables (lecture seule sur les données financières).';

    public function handle(SurveillanceEncaissements $surveillance, AlertesAnomaliesEncaissement $alertes): int
    {
        $lock = Cache::lock('controle-encaissements:scan', 1800);
        if (! $lock->get()) {
            $this->warn('Un contrôle est déjà en cours.');

            return self::SUCCESS;
        }

        $declencheur = $this->option('source') === ControleEncaissements::PLANIFICATEUR
            ? ControleEncaissements::PLANIFICATEUR
            : ControleEncaissements::COMMANDE;
        $organisations = $this->option('organization') ?: SurveillanceEncaissements::organisationsAControler()->all();
        $statut = self::SUCCESS;

        try {
            foreach ($organisations as $orgId) {
                try {
                    $c = $surveillance->controlerOrganisation($orgId, $declencheur);
                    $this->line("{$orgId} : {$c->nb_constats} constat(s), {$c->nb_nouvelles} nouvelle(s), {$c->nb_disparues} écart(s) disparu(s), {$c->nb_relances} relance(s)");
                } catch (Throwable $e) {
                    $statut = self::FAILURE;
                    $this->error("{$orgId} : {$e->getMessage()}");
                }
            }

            $bilan = $alertes->envoyerEnAttente();
            $this->info("E-mails : {$bilan['envoyes']} envoyé(s), {$bilan['echecs']} échec(s).");
        } finally {
            $lock->release();
        }

        return $statut;
    }
}
