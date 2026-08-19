<?php

namespace App\Console\Commands;

use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionGenerationAttempt;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Services\Commission\CommissionEnveloppeGenerator;
use Illuminate\Console\Command;

/**
 * Outil de vérification de parité Phase 1 (cf. conception cible §F, critère de
 * sortie Phase 1) : rejoue, pour une organisation, les ventes déjà commissionnées
 * par l'ancien moteur à travers le nouveau moteur (CommissionEnveloppeGenerator),
 * puis compare les montants finaux par bénéficiaire, TOLÉRANCE ZÉRO.
 *
 * Garanties de sécurité (vérifiées par tests/Feature/CommissionV2TransitionSafetyTest.php) :
 *  - Ne modifie JAMAIS `commissions_ventes` / `commission_parts` (l'ancien schéma
 *    n'est lu qu'en lecture, jamais écrit par ce moteur ni par cette commande).
 *  - Ne déclenche aucun paiement, aucun versement, aucune écriture de trésorerie.
 *  - Idempotent : une commande déjà rejouée (whereDoesntHave('commissionsV2'))
 *    n'est jamais retraitée, donc jamais dupliquée.
 *  - N'affecte aucun statut historique : les commissions/parts anciennes gardent
 *    exactement leur statut/montant/date, quoi qu'il arrive côté V2.
 */
class RejouerCommissionsV2Command extends Command
{
    protected $signature = 'commissions:v2:rejouer
                            {organization : ID de l\'organisation pilote}
                            {--limit=200 : Nombre maximum de commandes rejouées}';

    protected $description = 'Rejoue les ventes déjà commissionnées à travers le nouveau moteur et compare les montants (tolérance zéro)';

    private int $nbConformes = 0;

    private int $nbEcarts = 0;

    private int $nbErreursGeneration = 0;

    private float $ecartMax = 0.0;

    /** @var array<int, array<string, mixed>> */
    private array $rapportEcarts = [];

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

        $nbAnalysees = $commandes->count();

        if ($commandes->isEmpty()) {
            $this->info('Aucune commande à rejouer (déjà toutes traitées, ou aucune commission existante).');

            return self::SUCCESS;
        }

        $this->info("Rejeu de {$nbAnalysees} commande(s) — tolérance ZÉRO GNF...");
        $this->line('');

        foreach ($commandes as $commande) {
            $this->rejouerEtComparer($commande);
        }

        $this->afficherRapportFinal($nbAnalysees);

        return $this->nbEcarts > 0 || $this->nbErreursGeneration > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function rejouerEtComparer(CommandeVente $commande): void
    {
        // Pont Phase 1, réservé à cette commande de replay — jamais la voie réelle.
        CommissionEnveloppeGenerator::genererPourCommandeVenteMargeLegacy($commande);

        $ancienne = CommissionVente::where('commande_vente_id', $commande->id)->firstOrFail();
        $anciennesParts = CommissionPart::where('commission_vente_id', $ancienne->id)->get();

        $nouvellesEnveloppes = CommissionEnveloppe::where('source_type', CommandeVente::class)
            ->where('source_id', $commande->id)->get();

        $ref = $commande->reference ?? $commande->id;

        if ($nouvellesEnveloppes->isEmpty()) {
            $tentative = CommissionGenerationAttempt::where('source_id', $commande->id)->latest('created_at')->first();
            $this->nbErreursGeneration++;
            $this->error("✗ {$ref} — GÉNÉRATION V2 EN ERREUR : ".($tentative?->motif_erreur ?? 'inconnue'));

            return;
        }

        $nouvellesParts = CommissionEnveloppePart::whereIn('enveloppe_id', $nouvellesEnveloppes->pluck('id'))->get();

        $enveloppeProprietaire = $nouvellesEnveloppes->firstWhere('cible_type', 'proprietaire');
        $enveloppeLivraison = $nouvellesEnveloppes->firstWhere('cible_type', 'equipe_livraison');

        $ancienProprietaire = $anciennesParts->firstWhere('type_beneficiaire', 'proprietaire');
        $ancienTotalLivreurs = $anciennesParts->where('type_beneficiaire', 'livreur')->sum('montant_brut');

        $lignesEcart = [];

        // Total.
        $ecartTotal = $this->comparerMontant(
            (float) $ancienne->montant_commission_totale,
            (float) $nouvellesEnveloppes->sum('montant_total'),
        );
        if ($ecartTotal !== null) {
            $lignesEcart[] = "Total commission : ancien={$this->g((float) $ancienne->montant_commission_totale)} nouveau={$this->g((float) $nouvellesEnveloppes->sum('montant_total'))} écart={$this->g($ecartTotal)}";
        }

        // Propriétaire.
        if ($ancienProprietaire && $enveloppeProprietaire) {
            $ecartProp = $this->comparerMontant((float) $ancienProprietaire->montant_brut, (float) $enveloppeProprietaire->montant_total);
            if ($ecartProp !== null) {
                $lignesEcart[] = "Propriétaire : ancien={$this->g((float) $ancienProprietaire->montant_brut)} nouveau={$this->g((float) $enveloppeProprietaire->montant_total)} écart={$this->g($ecartProp)}";
            }
        }

        // Enveloppe livraison (total livreurs).
        if ($enveloppeLivraison) {
            $ecartLivraison = $this->comparerMontant((float) $ancienTotalLivreurs, (float) $enveloppeLivraison->montant_total);
            if ($ecartLivraison !== null) {
                $lignesEcart[] = "Total livreurs : ancien={$this->g((float) $ancienTotalLivreurs)} nouveau={$this->g((float) $enveloppeLivraison->montant_total)} écart={$this->g($ecartLivraison)}";
            }
        }

        // Parts individuelles.
        foreach ($anciennesParts as $ap) {
            $beneficiaireId = $ap->type_beneficiaire === 'proprietaire' ? $ap->proprietaire_id : $ap->livreur_id;
            $np = $nouvellesParts->first(fn ($p) => $p->beneficiaire_id === $beneficiaireId)
                ?? ($enveloppeProprietaire && $ap->type_beneficiaire === 'proprietaire' ? (object) ['montant_brut' => $enveloppeProprietaire->montant_total] : null);

            if (! $np) {
                $lignesEcart[] = "Bénéficiaire {$beneficiaireId} ({$ap->type_beneficiaire}) : ABSENT du nouveau moteur";

                continue;
            }

            $ecart = $this->comparerMontant((float) $ap->montant_brut, (float) $np->montant_brut);
            if ($ecart !== null) {
                $lignesEcart[] = "{$ap->type_beneficiaire} {$beneficiaireId} : ancien={$this->g((float) $ap->montant_brut)} nouveau={$this->g((float) $np->montant_brut)} écart={$this->g($ecart)}";
            }
        }

        if (empty($lignesEcart)) {
            $this->nbConformes++;
            $this->info("✓ {$ref} — CONFORME (écart 0 GNF)");

            return;
        }

        $this->nbEcarts++;
        $this->error("✗ {$ref} — ANCIEN ≠ V2");
        foreach ($lignesEcart as $l) {
            $this->line("    {$l}");
        }

        $vehicule = $commande->vehicule?->loadMissing('equipe.membres');
        $this->rapportEcarts[] = [
            'commande' => $ref,
            'vehicule' => $vehicule?->nom_vehicule,
            'taux_proprietaire' => $vehicule?->equipe?->taux_commission_proprietaire,
            'taux_membres' => $vehicule?->equipe?->membres?->pluck('taux_commission', 'livreur_id')->all(),
            'ecarts' => $lignesEcart,
        ];
    }

    /** Compare au centime près (tolérance ZÉRO) ; retourne l'écart signé, ou null si identique. */
    private function comparerMontant(float $ancien, float $nouveau): ?float
    {
        $a = number_format(round($ancien, 2), 2, '.', '');
        $n = number_format(round($nouveau, 2), 2, '.', '');
        if ($a === $n) {
            return null;
        }

        $ecart = round($nouveau - $ancien, 2);
        $this->ecartMax = max($this->ecartMax, abs($ecart));

        return $ecart;
    }

    private function g(float $v): string
    {
        return number_format($v, 2, ',', ' ').' GNF';
    }

    private function afficherRapportFinal(int $nbAnalysees): void
    {
        $this->line('');
        $this->line('════════════════════════════════════════');
        $this->info('RAPPORT DE PARITÉ PHASE 1');
        $this->line('════════════════════════════════════════');
        $this->line("Commandes analysées      : {$nbAnalysees}");
        $this->line("Conformes (écart 0 GNF)  : {$this->nbConformes}");
        $this->line("Avec écart (ANCIEN≠V2)   : {$this->nbEcarts}");
        $this->line("Génération V2 en erreur  : {$this->nbErreursGeneration}");
        $this->line('Écart maximal observé    : '.$this->g($this->ecartMax));
        $this->line('');

        if (! empty($this->rapportEcarts)) {
            $this->warn('Détail des écarts :');
            foreach ($this->rapportEcarts as $r) {
                $this->line("  Commande {$r['commande']} — véhicule {$r['vehicule']}");
                $this->line("    Taux propriétaire : {$r['taux_proprietaire']}%");
                $this->line('    Taux membres      : '.json_encode($r['taux_membres']));
                foreach ($r['ecarts'] as $e) {
                    $this->line("    → {$e}");
                }
            }
        }

        $this->line('════════════════════════════════════════');
        if ($this->nbEcarts === 0 && $this->nbErreursGeneration === 0) {
            $this->info('PARITÉ COMPLÈTE — 0 GNF d\'écart sur toutes les commandes analysées.');
        } else {
            $this->error('PARITÉ INCOMPLÈTE — ne pas activer le moteur V2, ni entamer la Phase 2, avant correction.');
        }
    }
}
