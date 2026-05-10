<?php

namespace Database\Seeders;

use App\Enums\BaseCalculLogistique;
use App\Enums\StatutCommission;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Database\Seeder;

/**
 * Seed 2 commissions logistiques avec parts couvrant les cas :
 *  TL-SEED-001 — elm-2 (Aissatou 70% + Thierno 30%) → IMPAYÉ  (5 600 + 2 400 GNF)
 *  TL-SEED-002 — elm-1 (Boubacar 100%)               → PAYÉ   (8 000 GNF)
 *
 * Ces données permettent aux tests E2E de vérifier le comportement
 * de « Déjà payé » après un paiement partiel (bug corrigé : SUM(montant_verse)).
 */
class CommissionLogistiqueSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();
        $user = User::where('organization_id', $org->id)->first();

        $siteSource = Site::where('organization_id', $org->id)->where('nom', 'Lansanaya')->first()
            ?? Site::where('organization_id', $org->id)->first();
        $siteDest = Site::where('organization_id', $org->id)->where('nom', 'Lambagny')->first()
            ?? Site::where('organization_id', $org->id)->skip(1)->first()
            ?? $siteSource;

        $vehElm1 = Vehicule::where('immatriculation', 'ELM-001-GN')->where('organization_id', $org->id)->first();
        $vehElm2 = Vehicule::where('immatriculation', 'ELM-002-GN')->where('organization_id', $org->id)->first();

        $aissatou = Livreur::where('telephone', '+224622000012')->where('organization_id', $org->id)->first();
        $thierno = Livreur::where('telephone', '+224622000013')->where('organization_id', $org->id)->first();
        $boubacar = Livreur::where('telephone', '+224622000011')->where('organization_id', $org->id)->first();

        if (! $siteSource || ! $user) {
            return;
        }

        // ── TL-SEED-001 : elm-2, Aissatou + Thierno, IMPAYÉ ────────────────────
        if ($vehElm2 && $aissatou && $thierno) {
            $tl1 = TransfertLogistique::firstOrCreate(
                ['reference' => 'TL-SEED-001'],
                [
                    'organization_id' => $org->id,
                    'site_source_id' => $siteSource->id,
                    'site_destination_id' => $siteDest->id,
                    'vehicule_id' => $vehElm2->id,
                    'statut' => 'cloture',
                    'date_arrivee_reelle' => '2026-04-01',
                    'created_by' => $user->id,
                ]
            );

            $cl1 = CommissionLogistique::firstOrCreate(
                ['transfert_logistique_id' => $tl1->id],
                [
                    'organization_id' => $org->id,
                    'vehicule_id' => $vehElm2->id,
                    'base_calcul' => BaseCalculLogistique::FORFAIT,
                    'valeur_base' => 8000,
                    'montant_total' => 8000,
                    'montant_verse' => 0,
                    'statut' => StatutCommission::IMPAYE,
                ]
            );

            CommissionLogistiquePart::firstOrCreate(
                ['commission_logistique_id' => $cl1->id, 'livreur_id' => $aissatou->id],
                [
                    'type_beneficiaire' => 'livreur',
                    'beneficiaire_nom' => 'Aissatou BALDÉ',
                    'taux_commission' => 70.00,
                    'montant_brut' => 5600,
                    'montant_net' => 5600,
                    'montant_verse' => 0,
                    'statut' => StatutCommission::IMPAYE,
                    'earned_at' => '2026-04-01',
                    'periode' => '2026-04',
                ]
            );

            CommissionLogistiquePart::firstOrCreate(
                ['commission_logistique_id' => $cl1->id, 'livreur_id' => $thierno->id],
                [
                    'type_beneficiaire' => 'livreur',
                    'beneficiaire_nom' => 'Thierno SALL',
                    'taux_commission' => 30.00,
                    'montant_brut' => 2400,
                    'montant_net' => 2400,
                    'montant_verse' => 0,
                    'statut' => StatutCommission::IMPAYE,
                    'earned_at' => '2026-04-01',
                    'periode' => '2026-04',
                ]
            );
        }

        // ── TL-SEED-002 : elm-1, Boubacar, PAYÉ ─────────────────────────────────
        if ($vehElm1 && $boubacar) {
            $tl2 = TransfertLogistique::firstOrCreate(
                ['reference' => 'TL-SEED-002'],
                [
                    'organization_id' => $org->id,
                    'site_source_id' => $siteSource->id,
                    'site_destination_id' => $siteDest->id,
                    'vehicule_id' => $vehElm1->id,
                    'statut' => 'cloture',
                    'date_arrivee_reelle' => '2026-04-05',
                    'created_by' => $user->id,
                ]
            );

            $cl2 = CommissionLogistique::firstOrCreate(
                ['transfert_logistique_id' => $tl2->id],
                [
                    'organization_id' => $org->id,
                    'vehicule_id' => $vehElm1->id,
                    'base_calcul' => BaseCalculLogistique::FORFAIT,
                    'valeur_base' => 8000,
                    'montant_total' => 8000,
                    'montant_verse' => 8000,
                    'statut' => StatutCommission::PAYE,
                ]
            );

            CommissionLogistiquePart::firstOrCreate(
                ['commission_logistique_id' => $cl2->id, 'livreur_id' => $boubacar->id],
                [
                    'type_beneficiaire' => 'livreur',
                    'beneficiaire_nom' => 'Boubacar KONATÉ',
                    'taux_commission' => 100.00,
                    'montant_brut' => 8000,
                    'montant_net' => 8000,
                    'montant_verse' => 8000,
                    'statut' => StatutCommission::PAYE,
                    'earned_at' => '2026-04-05',
                    'periode' => '2026-04',
                ]
            );
        }
    }
}
