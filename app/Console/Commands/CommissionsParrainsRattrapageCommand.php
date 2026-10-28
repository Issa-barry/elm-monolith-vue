<?php

namespace App\Console\Commands;

use App\Services\Commission\RattrapageFichesParrainService;
use Illuminate\Console\Command;

/**
 * Rattrapage des fiches de paiement des parrains manquantes (PARRAIN-017) — cf.
 * RattrapageFichesParrainService. Simulation par défaut (aucune écriture) ; `--appliquer` pour
 * régulariser.
 */
class CommissionsParrainsRattrapageCommand extends Command
{
    protected $signature = 'commissions:parrains-rattrapage
        {--appliquer : Régulariser (sans cette option : simulation, aucune écriture)}
        {--organisation= : Limiter à une organisation (id)}';

    protected $description = 'Fait entrer sur leur fiche les commissions de parrain générées sans fiche de paiement (période Parrains absente), sans régénérer ni vente ni commission.';

    public function handle(RattrapageFichesParrainService $rattrapage): int
    {
        $groupes = $rattrapage->analyser($this->option('organisation') ?: null);

        if ($groupes->isEmpty()) {
            $this->info('Aucune commission de parrain sans fiche : rien à régulariser.');

            return self::SUCCESS;
        }

        $appliquer = (bool) $this->option('appliquer');
        $this->line($appliquer ? 'Régularisation :' : 'Simulation (aucune écriture) — relancer avec --appliquer pour régulariser :');

        $resultats = $appliquer ? $rattrapage->appliquer($groupes) : $groupes;

        $this->table(
            ['Organisation', 'Période', 'Période existante', 'Parts', 'Montant (GNF)', 'Résultat'],
            $resultats->map(fn (array $g) => [
                $g['organization_id'],
                $g['reference'],
                $g['periode_existe'] ? ($g['statut_periode'] ?? 'oui') : 'non (sera créée)',
                $g['parts'],
                number_format($g['montant'], 0, ',', ' '),
                match ($g['action']) {
                    RattrapageFichesParrainService::REGULARISEE => 'régularisée',
                    RattrapageFichesParrainService::CLOTUREE => 'ignorée : période clôturée',
                    RattrapageFichesParrainService::ERREUR => 'erreur : '.($g['erreur'] ?? ''),
                    default => $appliquer ? '—' : 'à régulariser',
                },
            ])->all(),
        );

        $erreurs = $resultats->where('action', RattrapageFichesParrainService::ERREUR)->count();

        return $erreurs > 0 ? self::FAILURE : self::SUCCESS;
    }
}
