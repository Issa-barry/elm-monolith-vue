<?php

namespace App\Console\Commands;

use App\Enums\StatutFichePaiement;
use App\Models\PaiementFiche;
use App\Models\Site;
use App\Services\Commission\AgencePayeuseCommissionService;
use App\Services\Commission\FicheSiteResponsableService;
use App\Services\Comptabilite\FicheComptabilisationService;
use Illuminate\Console\Command;

/**
 * Rattrapage des agences des fiches de commission non entièrement payées : agence de rattachement
 * (site actuel des véhicules, ADR 0020) et agence qui paie (politique de paiement, ADR 0061). La
 * commande liste les fiches dont l'agence qui paie doit changer et ne les corrige qu'avec
 * --appliquer. Les fiches entièrement payées ne sont jamais touchées ; les paiements déjà faits
 * gardent leur agence.
 */
class CommissionsRealignerSitesFichesCommand extends Command
{
    protected $signature = 'commissions:realigner-sites-fiches
        {--organization= : ID d\'organisation ; toutes si omis}
        {--appliquer : Écrit les corrections (sinon simple aperçu)}';

    protected $description = 'Réaligne l\'agence qui paie les fiches de commission non payées sur la politique de paiement (aperçu par défaut).';

    public function handle(FicheSiteResponsableService $service, FicheComptabilisationService $comptabilite): int
    {
        $appliquer = (bool) $this->option('appliquer');
        $sites = Site::pluck('nom', 'id');

        $fiches = PaiementFiche::with('periode:id,reference')
            ->whereIn('beneficiaire_type', AgencePayeuseCommissionService::TYPES)
            ->where('statut', '!=', StatutFichePaiement::PAYE->value)
            ->when($this->option('organization'), fn ($q, $org) => $q->where('organization_id', $org))
            ->orderBy('reference')
            ->get();

        $lignes = [];
        $totaux = [];
        $rattachementsCorriges = 0;
        foreach ($fiches as $fiche) {
            [$rattachement, $payeur] = $service->sitesAttendus($fiche);
            $changePayeur = $payeur !== null && $payeur !== $fiche->site_id;

            if ($rattachement !== $fiche->site_rattachement_id) {
                $rattachementsCorriges++;
            }

            if ($changePayeur) {
                $libelle = AgencePayeuseCommissionService::LIBELLES[$fiche->beneficiaire_type] ?? $fiche->beneficiaire_type;
                $constatee = $comptabilite->estConstatee($fiche);
                $lignes[] = [
                    $fiche->reference,
                    $libelle,
                    $fiche->beneficiaire_nom,
                    $fiche->periode?->reference ?? '—',
                    number_format((float) $fiche->montant_net, 0, ',', ' '),
                    number_format((float) $fiche->montant_paye, 0, ',', ' '),
                    number_format($fiche->montant_restant, 0, ',', ' '),
                    $sites->get($fiche->site_id, '—'),
                    $sites->get($payeur, '—'),
                    $constatee ? 'oui (2 pièces)' : 'non',
                ];
                $totaux[$libelle] ??= ['fiches' => 0, 'reste' => 0.0, 'constatees' => 0];
                $totaux[$libelle]['fiches']++;
                $totaux[$libelle]['reste'] += $fiche->montant_restant;
                $totaux[$libelle]['constatees'] += $constatee ? 1 : 0;
            }

            if ($appliquer) {
                $service->realigner($fiche);
            }
        }

        if ($lignes === [] && $rattachementsCorriges === 0) {
            $this->info('Aucune fiche à réaligner.');

            return self::SUCCESS;
        }

        if ($lignes !== []) {
            $this->table(
                ['Fiche', 'Type', 'Bénéficiaire', 'Période', 'Net (GNF)', 'Déjà payé (GNF)', 'Reste déplacé (GNF)', 'Agence qui paie actuelle', 'Nouvelle agence qui paie', 'Écritures'],
                $lignes,
            );
            $this->table(
                ['Type', 'Fiches', 'Reste déplacé (GNF)', 'Fiches constatées (écritures)'],
                collect($totaux)->map(fn (array $t, string $type) => [
                    $type,
                    $t['fiches'],
                    number_format($t['reste'], 0, ',', ' '),
                    $t['constatees'],
                ])->values()->all(),
            );
        }

        $verbe = $appliquer ? 'corrigée(s).' : 'à corriger — relancer avec --appliquer.';
        $this->line(count($lignes).' fiche(s) changent d\'agence qui paie, '.$rattachementsCorriges.' agence(s) de rattachement '.$verbe);

        return self::SUCCESS;
    }
}
