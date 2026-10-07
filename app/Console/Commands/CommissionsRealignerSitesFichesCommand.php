<?php

namespace App\Console\Commands;

use App\Enums\StatutFichePaiement;
use App\Models\PaiementFiche;
use App\Models\Site;
use App\Services\Commission\FicheSiteResponsableService;
use Illuminate\Console\Command;

/**
 * Rattrapage de la règle du 06/10/2026 : une commission non payée se paie au site actuel du
 * véhicule. Les fiches livreur/propriétaire calculées avant cette règle portent le site majoritaire
 * des VENTES ; la commande liste celles dont le site doit changer et ne les corrige qu'avec
 * --appliquer. Les fiches entièrement payées ne sont jamais touchées.
 */
class CommissionsRealignerSitesFichesCommand extends Command
{
    protected $signature = 'commissions:realigner-sites-fiches
        {--organization= : ID d\'organisation ; toutes si omis}
        {--appliquer : Écrit les corrections (sinon simple aperçu)}';

    protected $description = 'Réaligne l\'agence des fiches livreur/propriétaire non payées sur le site actuel des véhicules (aperçu par défaut).';

    public function handle(FicheSiteResponsableService $service): int
    {
        $appliquer = (bool) $this->option('appliquer');
        $sites = Site::pluck('nom', 'id');

        $fiches = PaiementFiche::whereIn('beneficiaire_type', ['livreur', 'proprietaire'])
            ->where('statut', '!=', StatutFichePaiement::PAYE->value)
            ->when($this->option('organization'), fn ($q, $org) => $q->where('organization_id', $org))
            ->orderBy('reference')
            ->get();

        $lignes = [];
        foreach ($fiches as $fiche) {
            $site = $service->siteDeLaFiche($fiche);
            if ($site === null || $site === $fiche->site_id) {
                continue;
            }

            $lignes[] = [
                $fiche->reference,
                $fiche->beneficiaire_nom,
                number_format($fiche->montant_restant, 0, ',', ' '),
                $sites->get($fiche->site_id, '—'),
                $sites->get($site, '—'),
            ];

            if ($appliquer) {
                $fiche->update(['site_id' => $site]);
            }
        }

        if ($lignes === []) {
            $this->info('Aucune fiche à réaligner.');

            return self::SUCCESS;
        }

        $this->table(['Fiche', 'Bénéficiaire', 'Reste (GNF)', 'Agence actuelle', 'Agence du véhicule'], $lignes);
        $this->line(count($lignes).' fiche(s) '.($appliquer ? 'réalignée(s).' : 'à réaligner — relancer avec --appliquer pour corriger.'));

        return self::SUCCESS;
    }
}
