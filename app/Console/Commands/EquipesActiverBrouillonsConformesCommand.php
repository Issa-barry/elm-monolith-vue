<?php

namespace App\Console\Commands;

use App\Models\Vehicule;
use App\Services\Commission\ActivationEquipesBrouillonService;
use App\Services\Commission\PartageConformiteVehiculesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rattrapage du 25/10/2026 : une équipe brouillon (import flotte) ne s'active qu'au moment où son
 * partage est écrit. Celles devenues conformes par l'onglet Parrain (qui ne déclenchait pas
 * l'activation) ou avant ce mécanisme sont restées inactives, et toute distribution de leur
 * véhicule est refusée. Liste les équipes inactives dont le partage est conforme pour chaque
 * processus et ne les active qu'avec --appliquer, par ActivationEquipesBrouillonService — même
 * juge que la colonne « Partages ». Idempotente ; ne désactive jamais rien.
 */
class EquipesActiverBrouillonsConformesCommand extends Command
{
    protected $signature = 'equipes:activer-brouillons-conformes
        {--organization= : ID d\'organisation ; toutes si omis}
        {--appliquer : Active les équipes (sinon simple aperçu)}';

    protected $description = 'Active les équipes de livraison inactives dont le partage Livreur est conforme (aperçu par défaut).';

    public function handle(): int
    {
        $appliquer = (bool) $this->option('appliquer');
        $bloquants = [PartageConformiteVehiculesService::A_FAIRE, PartageConformiteVehiculesService::SANS_EQUIPE];

        $parOrganisation = Vehicule::with('equipe.membres.livreur')
            ->whereHas('equipe', fn ($q) => $q->where('is_active', false))
            ->when($this->option('organization'), fn ($q, $org) => $q->where('organization_id', $org))
            ->get()
            ->groupBy('organization_id');

        $lignes = [];
        $activees = 0;
        $restantes = 0;
        foreach ($parOrganisation as $orgId => $vehicules) {
            $statuts = PartageConformiteVehiculesService::statuts($orgId, $vehicules);

            foreach ($vehicules as $vehicule) {
                $aFaire = collect($statuts[$vehicule->id] ?? [])
                    ->filter(fn (string $s) => in_array($s, $bloquants, true))
                    ->keys();

                if ($aFaire->isNotEmpty()) {
                    $restantes++;

                    continue;
                }

                $lignes[] = [$vehicule->nom_vehicule, $vehicule->immatriculation, $vehicule->is_active ? 'actif' : 'inactif'];
            }

            if ($appliquer) {
                $activees += DB::transaction(fn () => ActivationEquipesBrouillonService::activerSiPartageConforme(
                    $orgId,
                    $vehicules->pluck('equipe.id'),
                ));
            }
        }

        if ($lignes === []) {
            $this->info('Aucune équipe inactive au partage conforme.');
        } else {
            $this->table(['Véhicule', 'Immatriculation', 'Véhicule actuellement'], $lignes);
            $this->line($appliquer
                ? "{$activees} équipe(s) activée(s) avec leur véhicule."
                : count($lignes).' équipe(s) à activer — relancer avec --appliquer.');
        }

        if ($restantes > 0) {
            $this->line("{$restantes} équipe(s) inactive(s) restent en attente d'un partage « À faire » (colonne « Partages » de la liste des véhicules).");
        }

        return self::SUCCESS;
    }
}
