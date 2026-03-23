<?php

namespace App\Console\Commands;

use App\Enums\StatutFactureVente;
use App\Models\CommissionVente;
use App\Models\FactureVente;
use Illuminate\Console\Command;

class GenererCommissionsManquantes extends Command
{
    protected $signature   = 'commissions:generer-manquantes';
    protected $description = 'Génère les commissions manquantes pour les factures déjà payées.';

    public function handle(): int
    {
        $factures = FactureVente::with('commande.vehicule.livreurPrincipal')
            ->where('statut_facture', StatutFactureVente::PAYEE)
            ->whereHas('commande', fn ($q) => $q->whereNotNull('vehicule_id'))
            ->get();

        $created = 0;

        foreach ($factures as $facture) {
            $commande = $facture->commande;
            if (!$commande) continue;

            $vehicule = $commande->vehicule;
            if (!$vehicule || !$vehicule->commission_active || $vehicule->taux_commission_livreur <= 0) continue;

            if (CommissionVente::where('commande_vente_id', $commande->id)->exists()) continue;

            $livreur = $vehicule->livreurPrincipal;

            CommissionVente::create([
                'organization_id'    => $commande->organization_id,
                'commande_vente_id'  => $commande->id,
                'vehicule_id'        => $vehicule->id,
                'livreur_id'         => $livreur?->id,
                'livreur_nom'        => $livreur ? trim($livreur->prenom . ' ' . $livreur->nom) : null,
                'taux_commission'    => $vehicule->taux_commission_livreur,
                'montant_commande'   => (float) $commande->total_commande,
                'montant_commission' => round((float) $commande->total_commande * ($vehicule->taux_commission_livreur / 100), 2),
            ]);

            $created++;
            $this->line("  Commission créée pour commande {$commande->reference}");
        }

        $this->info("✓ {$created} commission(s) générée(s).");
        return Command::SUCCESS;
    }
}
