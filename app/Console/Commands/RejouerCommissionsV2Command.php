<?php

namespace App\Console\Commands;

use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\Organization;
use App\Services\Commission\CommissionEnveloppeGenerator;
use Illuminate\Console\Command;

/**
 * Outil de vérification de parité Phase 1 (cf. conception cible §F, critère de
 * sortie Phase 1) : rejoue, pour une organisation, les ventes déjà commissionnées
 * par l'ancien moteur à travers le nouveau moteur (CommissionEnveloppeGenerator),
 * puis compare les montants finaux par bénéficiaire.
 *
 * N'écrit JAMAIS dans l'ancien schéma. Écrit réellement dans le nouveau
 * (commission_enveloppes / commission_enveloppe_parts / commission_generation_attempts)
 * — c'est le mécanisme même d'activation Phase 1 : rejouer l'historique d'une
 * organisation pilote sert à la fois de vérification ET de backfill.
 *
 * Usage :
 *   php artisan commissions:v2:rejouer {organization}
 */
class RejouerCommissionsV2Command extends Command
{
    protected $signature = 'commissions:v2:rejouer
                            {organization : ID de l\'organisation pilote}
                            {--limit=200 : Nombre maximum de commandes rejouées}';

    protected $description = 'Rejoue les ventes déjà commissionnées à travers le nouveau moteur et compare les montants';

    public function handle(): int
    {
        $orgId = $this->argument('organization');
        $limit = (int) $this->option('limit');

        $commandes = CommandeVente::query()
            ->where('organization_id', $orgId)
            ->whereHas('commissions')
            ->whereDoesntHave('commissionsV2')
            ->limit($limit)
            ->get();

        if ($commandes->isEmpty()) {
            $this->info('Aucune commande à rejouer (déjà toutes traitées, ou aucune commission existante).');

            return self::SUCCESS;
        }

        $this->info("Rejeu de {$commandes->count()} commande(s)...");

        $nbOk = 0;
        $nbEcart = 0;
        $nbErreur = 0;

        foreach ($commandes as $commande) {
            CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

            $resultat = $this->comparer($commande);

            match ($resultat) {
                'ok' => $nbOk++,
                'ecart' => $nbEcart++,
                'erreur' => $nbErreur++,
            };
        }

        $this->line('');
        $this->info("Parité exacte : {$nbOk}");
        if ($nbEcart > 0) {
            $this->warn("Écarts détectés : {$nbEcart}");
        }
        if ($nbErreur > 0) {
            $this->error("Génération en erreur (à régulariser) : {$nbErreur}");
        }

        return $nbEcart > 0 || $nbErreur > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function comparer(CommandeVente $commande): string
    {
        $ancienne = CommissionVente::where('commande_vente_id', $commande->id)->first();
        if (! $ancienne) {
            return 'ok';
        }

        $nouvelles = CommissionEnveloppe::where('source_type', CommandeVente::class)
            ->where('source_id', $commande->id)
            ->get();

        if ($nouvelles->isEmpty()) {
            $this->error("  Commande {$commande->id} : aucune enveloppe générée (à régulariser).");

            return 'erreur';
        }

        $totalNouveau = round((float) $nouvelles->sum('montant_total'), 2);
        $totalAncien = round((float) $ancienne->montant_commission_totale, 2);

        $ok = true;

        if (abs($totalNouveau - $totalAncien) > 0.01) {
            $this->warn("  Commande {$commande->id} : total ancien={$totalAncien} nouveau={$totalNouveau}");
            $ok = false;
        }

        $ancienParts = CommissionPart::where('commission_vente_id', $ancienne->id)->get();
        $nouvellesParts = CommissionEnveloppePart::whereIn('enveloppe_id', $nouvelles->pluck('id'))->get();

        foreach ($ancienParts as $ap) {
            $beneficiaireId = $ap->type_beneficiaire === 'proprietaire' ? $ap->proprietaire_id : $ap->livreur_id;
            $np = $nouvellesParts->first(fn ($p) => $p->beneficiaire_id === $beneficiaireId);

            if (! $np) {
                $this->warn("  Commande {$commande->id} : bénéficiaire {$beneficiaireId} absent du nouveau moteur");
                $ok = false;

                continue;
            }

            if (abs((float) $np->montant_brut - (float) $ap->montant_brut) > 0.01) {
                $this->warn(sprintf(
                    '  Commande %s : bénéficiaire %s ancien=%.2f nouveau=%.2f',
                    $commande->id,
                    $beneficiaireId,
                    (float) $ap->montant_brut,
                    (float) $np->montant_brut,
                ));
                $ok = false;
            }
        }

        return $ok ? 'ok' : 'ecart';
    }
}
