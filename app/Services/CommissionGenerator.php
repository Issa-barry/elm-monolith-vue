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

        // Éligibilité aux commissions figée au moment de la commande
        // (commission_eligible_snapshot, dérivée de Vehicule::livraison_vente) — notion
        // indépendante du mode de tarification (prix_vente/prix_usine, cf. ModeTarification).
        // Voir VehiculeCommandeContextResolver.
        if (! $commande->commission_eligible_snapshot) {
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

        // Toujours créée en CREEE : elle ne devient IMPAYE(E) qu'à la validation
        // de la période de paiement qui la couvre, jamais avant — cf.
        // CommissionAdjustmentService::activerCommissionsCreees().
        $commission = CommissionVente::create([
            'organization_id' => $commande->organization_id,
            'commande_vente_id' => $commande->id,
            'vehicule_id' => $vehicule->id,
            'montant_commande' => (float) $commande->total_commande,
            'montant_commission_totale' => $calc['commission_totale'],
            'montant_verse' => 0,
            'statut' => StatutCommission::CREEE->value,
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
                'statut' => StatutCommission::CREEE->value,
            ]);
        }

        // Une nouvelle commission sur une quinzaine déjà "Calculée" ne doit pas y rester
        // silencieusement absente : on recalcule immédiatement plutôt que d'attendre la
        // prochaine ouverture de la page période (cf. PeriodeCalculatorService). Date de
        // la commission elle-même (= validation du chargement), pas de la commande : ce
        // sont ces deux dates que PeriodeCalculatorService et PeriodePayabilityChecker
        // doivent utiliser de façon cohérente pour rattacher une part à sa période.
        app(PeriodeCalculatorService::class)->recalculerPeriodesConcernees($commande->organization_id, $commission->created_at);
    }
}
