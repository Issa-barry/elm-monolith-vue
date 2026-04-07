<?php

namespace Database\Seeders;

use App\Enums\StatutCommission;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Models\VersementCommission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed 6 commissions avec parts et versements couvrant tous les cas métier.
 *
 * Chaque commission correspond à une livraison réelle (commande fictive).
 * Les parts et versements sont créés directement (pas via FactureVente::genererCommission)
 * afin de contrôler précisément les montants et statuts seedés.
 *
 * Cas couverts :
 *  C1 – Équipe Nord (2 livreurs)  → VERSÉE entièrement
 *  C2 – Équipe Sud  (1 livreur)   → PARTIELLE (livreur versé, propriétaire partiel)
 *  C3 – Équipe Est  (2 livreurs)  → EN ATTENTE (aucun versement)
 *  C4 – Équipe Ouest (2 livreurs) → PARTIELLE + frais propriétaire déduits
 *  C5 – Équipe Centre (3 livreurs)→ EN ATTENTE (aucun versement)
 *  C6 – Équipe Nord (2 livreurs)  → VERSÉE entièrement (2ᵉ commission du même véhicule)
 *
 * Contrainte vérifiée : taux_proprietaire + Σ taux_membres == 100 par véhicule.
 */
class CommissionsSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        // ── Helpers ───────────────────────────────────────────────────────────
        $veh = fn (string $immat) => Vehicule::where('immatriculation', $immat)
            ->where('organization_id', $org->id)->firstOrFail();

        $prop = fn (string $tel) => Proprietaire::where('telephone', $tel)
            ->where('organization_id', $org->id)->firstOrFail();

        $liv = fn (string $tel) => Livreur::where('telephone', $tel)
            ->where('organization_id', $org->id)->firstOrFail();

        // ── Entités référencées ───────────────────────────────────────────────
        $camionAlpha = $veh('RC-001-GN');  // Équipe Nord : Ibrahima 5%, Sékou 3%, prop 92%
        $tricycle01 = $veh('TC-001-GN');  // Équipe Sud  : Mariama 6%, prop 94%
        $vanneExpress = $veh('VN-001-GN');  // Équipe Est  : Mamadou 5%, Fatoumata K. 3%, prop 92%
        $camionBeta = $veh('RC-002-GN');  // Équipe Ouest: Boubacar 7%, Alpha 2%, prop 91%
        $tricycle02 = $veh('TC-002-GN');  // Équipe Centre: Oumar 5%, Abdoulaye 3%, Kadiatou 2%, prop 90%
        $tricycle03 = $veh('TC-003-GN');  // Équipe Nord : Ibrahima 5%, Sékou 3%, prop 92%

        $pBarry = $prop('+224621000001');  // Mamadou BARRY
        $pDiallo = $prop('+224621000002');  // Fatoumata DIALLO
        $pTounkara = $prop('+224621000003');  // Issa TOUNKARA
        $pConde = $prop('+224621000004');  // Saran CONDÉ

        $ibrahima = $liv('+224622000001');
        $sekou = $liv('+224622000002');
        $mariama = $liv('+224622000003');
        $mamadouS = $liv('+224622000004');
        $fatoumataK = $liv('+224622000005');
        $boubacar = $liv('+224622000006');
        $alpha = $liv('+224622000007');
        $oumar = $liv('+224622000008');
        $abdoulaye = $liv('+224622000009');
        $kadiatou = $liv('+224622000010');

        // ── Commandes fictives (insertion directe pour contourner les boot hooks) ──
        // On vérifie d'abord que les references n'existent pas déjà.
        $refs = ['VNT-SEED-001', 'VNT-SEED-002', 'VNT-SEED-003', 'VNT-SEED-004', 'VNT-SEED-005', 'VNT-SEED-006'];
        $existing = DB::table('commandes_ventes')
            ->whereIn('reference', $refs)
            ->pluck('id', 'reference')
            ->all();

        $commandeIds = [];
        $now = now()->toDateTimeString();

        foreach ([
            ['ref' => 'VNT-SEED-001', 'total' => 200000, 'vehicule' => $camionAlpha],
            ['ref' => 'VNT-SEED-002', 'total' => 100000, 'vehicule' => $tricycle01],
            ['ref' => 'VNT-SEED-003', 'total' => 150000, 'vehicule' => $vanneExpress],
            ['ref' => 'VNT-SEED-004', 'total' => 300000, 'vehicule' => $camionBeta],
            ['ref' => 'VNT-SEED-005', 'total' => 80000,  'vehicule' => $tricycle02],
            ['ref' => 'VNT-SEED-006', 'total' => 120000, 'vehicule' => $tricycle03],
        ] as $cmd) {
            if (isset($existing[$cmd['ref']])) {
                $commandeIds[$cmd['ref']] = $existing[$cmd['ref']];
            } else {
                $commandeIds[$cmd['ref']] = DB::table('commandes_ventes')->insertGetId([
                    'organization_id' => $org->id,
                    'vehicule_id' => $cmd['vehicule']->id,
                    'reference' => $cmd['ref'],
                    'total_commande' => $cmd['total'],
                    'statut' => 'livree',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // ── Helper : créer ou récupérer une CommissionVente ───────────────────
        $makeCommission = function (array $data) use ($org): CommissionVente {
            return CommissionVente::firstOrCreate(
                ['commande_vente_id' => $data['commande_vente_id'], 'organization_id' => $org->id],
                [
                    'vehicule_id' => $data['vehicule_id'],
                    'montant_commande' => $data['montant_commande'],
                    'montant_commission_totale' => $data['montant_commission_totale'],
                    'montant_verse' => 0,
                    'statut' => StatutCommission::EN_ATTENTE,
                ]
            );
        };

        // ── Helper : créer une CommissionPart ────────────────────────────────
        $makePart = function (CommissionVente $commission, array $data): CommissionPart {
            return CommissionPart::firstOrCreate(
                ['commission_vente_id' => $commission->id, 'beneficiaire_nom' => $data['beneficiaire_nom']],
                [
                    'type_beneficiaire' => $data['type'],
                    'livreur_id' => $data['livreur_id'] ?? null,
                    'proprietaire_id' => $data['proprietaire_id'] ?? null,
                    'beneficiaire_nom' => $data['beneficiaire_nom'],
                    'taux_commission' => $data['taux'],
                    'montant_brut' => $data['brut'],
                    'frais_supplementaires' => $data['frais'] ?? 0,
                    'montant_net' => $data['net'] ?? $data['brut'],
                    'montant_verse' => 0,
                    'statut' => StatutCommission::EN_ATTENTE,
                ]
            );
        };

        // ── Helper : créer un versement (déclenche recalculStatut via boot) ──
        $makeVersement = function (CommissionPart $part, float $montant, string $mode = 'especes', ?string $date = null): void {
            // Idempotent : ne recrée pas un versement identique
            $alreadyExists = $part->versements()
                ->where('montant', $montant)
                ->where('mode_paiement', $mode)
                ->exists();
            if ($alreadyExists) {
                return;
            }
            VersementCommission::create([
                'commission_part_id' => $part->id,
                'montant' => $montant,
                'date_versement' => $date ?? now()->subDays(random_int(1, 30))->format('Y-m-d'),
                'mode_paiement' => $mode,
                'note' => null,
            ]);
            // Boot hook VersementCommission::created déclenche :
            //   part->recalculStatut() → commission->recalculStatutGlobal()
        };

        // ══════════════════════════════════════════════════════════════════════
        // C1 — Camion Alpha · Équipe Nord · VERSÉE
        // Marge : 200 000 - 150 000 = 50 000 GNF
        //   Ibrahima  5% →  2 500 GNF  (versé)
        //   Sékou     3% →  1 500 GNF  (versé)
        //   M. BARRY 92% → 46 000 GNF  (versé)
        // ══════════════════════════════════════════════════════════════════════
        $c1 = $makeCommission([
            'commande_vente_id' => $commandeIds['VNT-SEED-001'],
            'vehicule_id' => $camionAlpha->id,
            'montant_commande' => 200000,
            'montant_commission_totale' => 50000,
        ]);

        $c1p_ibrahima = $makePart($c1, [
            'type' => 'livreur', 'livreur_id' => $ibrahima->id,
            'beneficiaire_nom' => 'Ibrahima CAMARA', 'taux' => 5.00, 'brut' => 2500,
        ]);
        $c1p_sekou = $makePart($c1, [
            'type' => 'livreur', 'livreur_id' => $sekou->id,
            'beneficiaire_nom' => 'Sékou KOUYATÉ', 'taux' => 3.00, 'brut' => 1500,
        ]);
        $c1p_barry = $makePart($c1, [
            'type' => 'proprietaire', 'proprietaire_id' => $pBarry->id,
            'beneficiaire_nom' => 'Mamadou BARRY', 'taux' => 92.00, 'brut' => 46000,
        ]);

        $makeVersement($c1p_ibrahima, 2500, 'especes', '2026-02-10');
        $makeVersement($c1p_sekou, 1500, 'mobile_money', '2026-02-10');
        $makeVersement($c1p_barry, 46000, 'virement', '2026-02-12');

        // ══════════════════════════════════════════════════════════════════════
        // C2 — Tricycle 01 · Équipe Sud · PARTIELLE
        // Marge : 100 000 - 75 000 = 25 000 GNF
        //   Mariama    6% →  1 500 GNF  (versé intégralement)
        //   F. DIALLO 94% → 23 500 GNF  (10 000 versé, reste 13 500)
        // ══════════════════════════════════════════════════════════════════════
        $c2 = $makeCommission([
            'commande_vente_id' => $commandeIds['VNT-SEED-002'],
            'vehicule_id' => $tricycle01->id,
            'montant_commande' => 100000,
            'montant_commission_totale' => 25000,
        ]);

        $c2p_mariama = $makePart($c2, [
            'type' => 'livreur', 'livreur_id' => $mariama->id,
            'beneficiaire_nom' => 'Mariama BAH', 'taux' => 6.00, 'brut' => 1500,
        ]);
        $c2p_diallo = $makePart($c2, [
            'type' => 'proprietaire', 'proprietaire_id' => $pDiallo->id,
            'beneficiaire_nom' => 'Fatoumata DIALLO', 'taux' => 94.00, 'brut' => 23500,
        ]);

        $makeVersement($c2p_mariama, 1500, 'especes', '2026-02-15');
        $makeVersement($c2p_diallo, 10000, 'especes', '2026-02-18');
        // 13 500 restants → statut PARTIELLE

        // ══════════════════════════════════════════════════════════════════════
        // C3 — Vanne Express · Équipe Est · EN ATTENTE
        // Marge : 150 000 - 120 000 = 30 000 GNF
        //   Mamadou S.  5% →  1 500 GNF  (non versé)
        //   Fatoumata K 3% →    900 GNF  (non versé)
        //   M. BARRY   92% → 27 600 GNF  (non versé)
        // ══════════════════════════════════════════════════════════════════════
        $c3 = $makeCommission([
            'commande_vente_id' => $commandeIds['VNT-SEED-003'],
            'vehicule_id' => $vanneExpress->id,
            'montant_commande' => 150000,
            'montant_commission_totale' => 30000,
        ]);

        $makePart($c3, [
            'type' => 'livreur', 'livreur_id' => $mamadouS->id,
            'beneficiaire_nom' => 'Mamadou SOUMAH', 'taux' => 5.00, 'brut' => 1500,
        ]);
        $makePart($c3, [
            'type' => 'livreur', 'livreur_id' => $fatoumataK->id,
            'beneficiaire_nom' => 'Fatoumata KOUROUMA', 'taux' => 3.00, 'brut' => 900,
        ]);
        $makePart($c3, [
            'type' => 'proprietaire', 'proprietaire_id' => $pBarry->id,
            'beneficiaire_nom' => 'Mamadou BARRY', 'taux' => 92.00, 'brut' => 27600,
        ]);
        // Aucun versement → statut EN_ATTENTE (valeur par défaut)

        // ══════════════════════════════════════════════════════════════════════
        // C4 — Camion Bêta · Équipe Ouest · PARTIELLE + frais propriétaire
        // Marge : 300 000 - 220 000 = 80 000 GNF
        //   Boubacar    7% →  5 600 GNF brut  (versé intégralement)
        //   Alpha       2% →  1 600 GNF brut  (versé intégralement)
        //   I. TOUNKARA91% → 72 800 GNF brut, frais 5 000, net 67 800
        //                    30 000 versé, reste 37 800
        // ══════════════════════════════════════════════════════════════════════
        $c4 = $makeCommission([
            'commande_vente_id' => $commandeIds['VNT-SEED-004'],
            'vehicule_id' => $camionBeta->id,
            'montant_commande' => 300000,
            'montant_commission_totale' => 80000,
        ]);

        $c4p_boubacar = $makePart($c4, [
            'type' => 'livreur', 'livreur_id' => $boubacar->id,
            'beneficiaire_nom' => 'Boubacar DIALLO', 'taux' => 7.00, 'brut' => 5600,
        ]);
        $c4p_alpha = $makePart($c4, [
            'type' => 'livreur', 'livreur_id' => $alpha->id,
            'beneficiaire_nom' => 'Alpha BARRY', 'taux' => 2.00, 'brut' => 1600,
        ]);
        $c4p_tounkara = $makePart($c4, [
            'type' => 'proprietaire',
            'proprietaire_id' => $pTounkara->id,
            'beneficiaire_nom' => 'Issa TOUNKARA',
            'taux' => 91.00,
            'brut' => 72800,
            'frais' => 5000,
            'net' => 67800,   // brut - frais
        ]);

        $makeVersement($c4p_boubacar, 5600, 'especes', '2026-03-01');
        $makeVersement($c4p_alpha, 1600, 'mobile_money', '2026-03-01');
        $makeVersement($c4p_tounkara, 30000, 'virement', '2026-03-05');
        // 37 800 restants pour Tounkara → statut PARTIELLE

        // ══════════════════════════════════════════════════════════════════════
        // C5 — Tricycle 02 · Équipe Centre · EN ATTENTE
        // Marge : 80 000 - 60 000 = 20 000 GNF
        //   Oumar       5% →  1 000 GNF  (non versé)
        //   Abdoulaye   3% →    600 GNF  (non versé)
        //   Kadiatou    2% →    400 GNF  (non versé)
        //   S. CONDÉ   90% → 18 000 GNF  (non versé)
        // ══════════════════════════════════════════════════════════════════════
        $c5 = $makeCommission([
            'commande_vente_id' => $commandeIds['VNT-SEED-005'],
            'vehicule_id' => $tricycle02->id,
            'montant_commande' => 80000,
            'montant_commission_totale' => 20000,
        ]);

        $makePart($c5, [
            'type' => 'livreur', 'livreur_id' => $oumar->id,
            'beneficiaire_nom' => 'Oumar CAMARA', 'taux' => 5.00, 'brut' => 1000,
        ]);
        $makePart($c5, [
            'type' => 'livreur', 'livreur_id' => $abdoulaye->id,
            'beneficiaire_nom' => 'Abdoulaye SYLLA', 'taux' => 3.00, 'brut' => 600,
        ]);
        $makePart($c5, [
            'type' => 'livreur', 'livreur_id' => $kadiatou->id,
            'beneficiaire_nom' => 'Kadiatou TOURÉ', 'taux' => 2.00, 'brut' => 400,
        ]);
        $makePart($c5, [
            'type' => 'proprietaire', 'proprietaire_id' => $pConde->id,
            'beneficiaire_nom' => 'Saran CONDÉ', 'taux' => 90.00, 'brut' => 18000,
        ]);
        // Aucun versement → EN_ATTENTE

        // ══════════════════════════════════════════════════════════════════════
        // C6 — Tricycle 03 · Équipe Nord · VERSÉE
        // Marge : 120 000 - 90 000 = 30 000 GNF
        //   Ibrahima  5% →  1 500 GNF  (versé)
        //   Sékou     3% →    900 GNF  (versé)
        //   I. TOUNKARA 92% → 27 600 GNF  (versé)
        // ══════════════════════════════════════════════════════════════════════
        $c6 = $makeCommission([
            'commande_vente_id' => $commandeIds['VNT-SEED-006'],
            'vehicule_id' => $tricycle03->id,
            'montant_commande' => 120000,
            'montant_commission_totale' => 30000,
        ]);

        $c6p_ibrahima = $makePart($c6, [
            'type' => 'livreur', 'livreur_id' => $ibrahima->id,
            'beneficiaire_nom' => 'Ibrahima CAMARA', 'taux' => 5.00, 'brut' => 1500,
        ]);
        $c6p_sekou = $makePart($c6, [
            'type' => 'livreur', 'livreur_id' => $sekou->id,
            'beneficiaire_nom' => 'Sékou KOUYATÉ', 'taux' => 3.00, 'brut' => 900,
        ]);
        $c6p_tounkara = $makePart($c6, [
            'type' => 'proprietaire', 'proprietaire_id' => $pTounkara->id,
            'beneficiaire_nom' => 'Issa TOUNKARA', 'taux' => 92.00, 'brut' => 27600,
        ]);

        $makeVersement($c6p_ibrahima, 1500, 'especes', '2026-03-20');
        $makeVersement($c6p_sekou, 900, 'especes', '2026-03-20');
        $makeVersement($c6p_tounkara, 27600, 'virement', '2026-03-22');
    }
}
