<?php

namespace App\Services;

use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use Illuminate\Support\Facades\Log;

class CommissionGenerator
{
    public static function generateForCommandeIfMissing(CommandeVente $commande): void
    {
        $commande->loadMissing([
            'lignes',
            'vehicule.equipe.membres.livreur',
            'vehicule.proprietaire',
        ]);

        if (! $commande->vehicule_id || ! $commande->vehicule) {
            return;
        }

        if (CommissionVente::where('commande_vente_id', $commande->id)->exists()) {
            return;
        }

        try {
            $calc = CommissionCalculator::fromCommande($commande);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Commission non generee : '.$e->getMessage(), [
                'commande_id' => $commande->id,
            ]);

            return;
        }

        $vehicule = $commande->vehicule;

        // Sécurité : si la commande est déjà encaissable (legacy / appel hors workflow normal),
        // on active directement plutôt que de créer une commission qui resterait bloquée à CREEE.
        $statutInitial = $commande->isEncaissable() ? StatutCommission::IMPAYE : StatutCommission::CREEE;

        $commission = CommissionVente::create([
            'organization_id' => $commande->organization_id,
            'commande_vente_id' => $commande->id,
            'vehicule_id' => $vehicule->id,
            'montant_commande' => (float) $commande->total_commande,
            'montant_commission_totale' => $calc['commission_totale'],
            'montant_verse' => 0,
            'statut' => $statutInitial->value,
        ]);

        foreach ($calc['parts'] as $part) {
            CommissionPart::create([
                'commission_vente_id' => $commission->id,
                'type_beneficiaire' => $part['type_beneficiaire'],
                'livreur_id' => $part['livreur_id'],
                'proprietaire_id' => $part['proprietaire_id'],
                'beneficiaire_nom' => $part['beneficiaire_nom'],
                'role' => $part['role'] ?? null,
                'taux_commission' => $part['taux_commission'],
                'montant_brut' => $part['montant_brut'],
                'frais_supplementaires' => $part['frais_supplementaires'],
                'montant_net' => $part['montant_net'],
                'montant_verse' => 0,
                'statut' => $statutInitial->value,
            ]);
        }

        // Une nouvelle commission sur une quinzaine déjà "Calculée" ne doit pas y rester
        // silencieusement absente : on recalcule immédiatement plutôt que d'attendre la
        // prochaine ouverture de la page période (cf. PeriodeCalculatorService).
        app(PeriodeCalculatorService::class)->recalculerPeriodesConcernees($commande->organization_id, $commande->created_at);
    }
}
