<?php

namespace App\Console\Commands;

use App\Services\Comptabilite\PiecesEncaissementSansEncaissementService;
use Illuminate\Console\Command;

/**
 * Rapport (par défaut) ou régularisation (--appliquer) des pièces de caisse sans encaissement
 * (incident VTE-081126-134, ADR 0062). La régularisation de production passe par la migration
 * 2026_11_09_210000 ; cette commande sert au contrôle et aux environnements avec accès console.
 */
class PiecesEncaissementSansEncaissementCommand extends Command
{
    protected $signature = 'comptabilite:pieces-encaissement-sans-encaissement {--appliquer : contrepasse les pièces confirmées}';

    protected $description = 'Rapport ou contrepassation des pièces d\'encaissement dont l\'encaissement n\'existe plus (ADR 0062).';

    public function handle(PiecesEncaissementSansEncaissementService $service): int
    {
        $constats = $service->analyser();
        $this->table(
            ['Pièce', 'Facture', 'Statut facture', 'Facturé', 'Encaissé réel', 'Débit', 'Caisse', 'Crédit', 'Décision', 'Raison'],
            array_map(fn (array $c) => [
                $c['numero'], $c['facture'] ?? '—', $c['statut_facture'] ?? '—', $c['montant_facture'], $c['encaisse_reel'],
                $c['compte_debit'].' '.$c['debit'], $c['caisse'], $c['compte_credit'].' '.$c['credit'], $c['decision'], $c['raison'],
            ], $constats),
        );

        if (! $this->option('appliquer')) {
            $this->info(count($constats).' pièce(s) analysée(s). Simulation : rien n\'a été écrit (--appliquer pour contrepasser).');

            return self::SUCCESS;
        }

        $bilan = $service->regulariser();
        foreach ($bilan['contrepassees'] as $c) {
            $this->line("{$c['numero']} contrepassée par {$c['contrepassation']} ({$c['caisse']} : -{$c['debit']})");
        }
        foreach ($bilan['echecs'] as $c) {
            $this->error("{$c['numero']} non régularisée : {$c['erreur']}");
        }
        $this->info(count($bilan['contrepassees']).' contrepassée(s), '.count($bilan['a_examiner']).' à examiner, '.count($bilan['echecs']).' échec(s).');

        return $bilan['echecs'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
