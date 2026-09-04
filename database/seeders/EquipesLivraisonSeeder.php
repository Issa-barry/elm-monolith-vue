<?php

namespace Database\Seeders;

use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Database\Seeder;

/**
 * Crée les équipes de livraison avec leurs membres et la répartition par montant/pack.
 *
 * Règle: somme(montants membres) + montant_proprietaire = commission_unitaire_par_pack
 *        taux = montant / commission * 100 (calculé et stocké à la sauvegarde)
 *
 * Équipes EXTERNES (commission 200 GNF/pack) :
 * | Vehicule       | Proprietaire      | Prop GNF | Chauffeur         | GNF | Convoyeur(s)          | GNF   |
 * |----------------|-------------------|----------|-------------------|-----|-----------------------|-------|
 * | Nen Dow        | Amadou DIALLO     | 120      | Ibrahima CAMARA   | 50  | Sekou KOUYATE         | 30    |
 * | Auto Dogomet   | Fatoumata DIALLO  | 120      | Mariama BAH       | 80  | -                     | -     |
 * | Baba Ousou     | Amadou DIALLO     | 120      | Oumar CAMARA      | 40  | A. SYLLA, K. TOURE    | 30+10 |
 * | Kaloum Express | Issa TOUNKARA     | 130      | Mamadou SOUMAH    | 50  | Fatoumata KOUROUMA    | 20    |
 * | Conakry 2      | Amadou DIALLO     | 120      | Boubacar DIALLO   | 80  | -                     | -     |
 *
 * Équipes INTERNES (commission 200 GNF/pack — 100 % aux membres) :
 * | Vehicule         | Prop GNF | Chauffeur          | GNF | Convoyeur(s)                   | GNF    |
 * |------------------|----------|--------------------|-----|--------------------------------|--------|
 * | ELM Logistique 1 | 0        | Boubacar KONATÉ    | 200 | -                              | -      |
 * | ELM Logistique 2 | 0        | Aissatou BALDÉ     | 140 | Thierno SALL                   | 60     |
 * | ELM Logistique 3 | 0        | Mamadou KEÏTA      | 100 | Djénabou TRAORÉ, Lamine FOFANA | 60+40  |
 * | ELM Logistique 4 | 0        | Alpha BARRY        | 200 | -                              | -      |
 */
class EquipesLivraisonSeeder extends Seeder
{
    private const COMMISSION = 200; // GNF par pack

    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $lv = fn (string $tel) => Livreur::query()
            ->where('organization_id', $org->id)
            ->whereHas('personne', fn ($q) => $q->where('telephone_normalise', Personne::normaliserTelephone($tel)))
            ->firstOrFail();

        $prop = fn (string $tel) => Proprietaire::query()
            ->where('organization_id', $org->id)
            ->whereHas('personne', fn ($q) => $q->where('telephone_normalise', Personne::normaliserTelephone($tel)))
            ->firstOrFail();

        // ── Équipes EXTERNES ──────────────────────────────────────────────────

        $equipesExternes = [
            [
                'proprietaire_tel' => '+33754158797',
                'membres' => [
                    ['telephone' => '+224622000001', 'role' => 'chauffeur', 'montant' => 50, 'ordre' => 0],
                    ['telephone' => '+224622000002', 'role' => 'convoyeur', 'montant' => 30, 'ordre' => 1],
                ],
            ],
            [
                'proprietaire_tel' => '+224621000002',
                'membres' => [
                    ['telephone' => '+224622000003', 'role' => 'chauffeur', 'montant' => 80, 'ordre' => 0],
                ],
            ],
            [
                'proprietaire_tel' => '+33754158797',
                'membres' => [
                    ['telephone' => '+224622000008', 'role' => 'chauffeur', 'montant' => 40, 'ordre' => 0],
                    ['telephone' => '+224622000009', 'role' => 'convoyeur', 'montant' => 30, 'ordre' => 1],
                    ['telephone' => '+224622000010', 'role' => 'convoyeur', 'montant' => 10, 'ordre' => 2],
                ],
            ],
            [
                'proprietaire_tel' => '+224621000003',
                'membres' => [
                    ['telephone' => '+224622000004', 'role' => 'chauffeur', 'montant' => 50, 'ordre' => 0],
                    ['telephone' => '+224622000005', 'role' => 'convoyeur', 'montant' => 20, 'ordre' => 1],
                ],
            ],
            [
                'proprietaire_tel' => '+33754158797',
                'membres' => [
                    ['telephone' => '+224622000006', 'role' => 'chauffeur', 'montant' => 80, 'ordre' => 0],
                ],
            ],
        ];

        foreach ($equipesExternes as $equipeData) {
            $proprietaire = $prop($equipeData['proprietaire_tel']);
            $sommeMembres = array_sum(array_column($equipeData['membres'], 'montant'));
            $montantProp = self::COMMISSION - $sommeMembres;
            $tauxProp = round($montantProp / self::COMMISSION * 100, 2);

            $equipe = EquipeLivraison::create([
                'organization_id' => $org->id,
                'is_active' => true,
                'proprietaire_id' => $proprietaire->id,
                'commission_unitaire_par_pack' => self::COMMISSION,
                'montant_par_pack_proprietaire' => $montantProp,
                'taux_commission_proprietaire' => $tauxProp,
            ]);

            foreach ($equipeData['membres'] as $m) {
                $livreur = $lv($m['telephone']);
                $taux = round($m['montant'] / self::COMMISSION * 100, 2);
                EquipeLivreur::updateOrCreate(
                    ['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id],
                    [
                        'role' => $m['role'],
                        'montant_par_pack' => $m['montant'],
                        'taux_commission' => $taux,
                        'ordre' => $m['ordre'],
                    ]
                );
            }
        }

        // ── Équipes INTERNES ──────────────────────────────────────────────────

        $equipesInternes = [
            [
                'membres' => [
                    ['telephone' => '+224622000011', 'role' => 'chauffeur', 'montant' => 200, 'ordre' => 0],
                ],
            ],
            [
                'membres' => [
                    ['telephone' => '+224622000012', 'role' => 'chauffeur', 'montant' => 140, 'ordre' => 0],
                    ['telephone' => '+224622000013', 'role' => 'convoyeur', 'montant' => 60, 'ordre' => 1],
                ],
            ],
            [
                'membres' => [
                    ['telephone' => '+224622000014', 'role' => 'chauffeur', 'montant' => 100, 'ordre' => 0],
                    ['telephone' => '+224622000015', 'role' => 'convoyeur', 'montant' => 60, 'ordre' => 1],
                    ['telephone' => '+224622000016', 'role' => 'convoyeur', 'montant' => 40, 'ordre' => 2],
                ],
            ],
            [
                'membres' => [
                    ['telephone' => '+224622000007', 'role' => 'chauffeur', 'montant' => 200, 'ordre' => 0],
                ],
            ],
            [
                'membres' => [
                    ['telephone' => '+224621346981', 'role' => 'chauffeur', 'montant' => 50, 'ordre' => 0],
                    ['telephone' => '+224624099568', 'role' => 'chauffeur', 'montant' => 50, 'ordre' => 1],
                    ['telephone' => '+224622458645', 'role' => 'convoyeur', 'montant' => 30, 'ordre' => 2],
                    ['telephone' => '+224623479658', 'role' => 'convoyeur', 'montant' => 25, 'ordre' => 3],
                    ['telephone' => '+224625145898', 'role' => 'convoyeur', 'montant' => 25, 'ordre' => 4],
                    ['telephone' => '+224623146589', 'role' => 'convoyeur', 'montant' => 20, 'ordre' => 5],
                ],
            ],
        ];

        $equipesInternesCreees = [];

        foreach ($equipesInternes as $equipeData) {
            $equipe = EquipeLivraison::create([
                'organization_id' => $org->id,
                'is_active' => true,
                'proprietaire_id' => null,
                'commission_unitaire_par_pack' => self::COMMISSION,
                'montant_par_pack_proprietaire' => null,
                'taux_commission_proprietaire' => 0,
            ]);

            foreach ($equipeData['membres'] as $m) {
                $taux = round($m['montant'] / self::COMMISSION * 100, 2);
                EquipeLivreur::updateOrCreate(
                    ['equipe_id' => $equipe->id, 'livreur_id' => $lv($m['telephone'])->id],
                    [
                        'role' => $m['role'],
                        'montant_par_pack' => $m['montant'],
                        'taux_commission' => $taux,
                        'ordre' => $m['ordre'],
                    ]
                );
            }

            $equipesInternesCreees[] = ['equipe' => $equipe, 'membres' => $equipeData['membres']];
        }

        // ── Barème "moteur générique" (commission logistique) ──────────────────
        // Décision produit du 03/09/2026 (cf. CommissionTriggerService::onTransfertReceptionEffectuee) :
        // CommissionEnveloppeGenerator est désormais le SEUL moteur de commission logistique — sans
        // CommissionRegle + partages par catégorie, le montant résout silencieusement à 0 (décision
        // AMOA #4, cf. CommissionEnveloppeGenerator::executerAvecTentative()). Ce bloc réexprime dans
        // le nouveau schéma le même barème 200 GNF/pack déjà documenté ci-dessus pour les équipes
        // INTERNES (100 % aux membres, aucune part propriétaire). Les équipes EXTERNES (part
        // propriétaire à modéliser séparément, cf. pattern cible PROPRIETAIRE/EQUIPE_LIVRAISON de
        // SeedCommissionsV2FondationsCommand pour la Vente) ne sont pas couvertes ici : aucun test ne
        // les exerce actuellement pour la commission logistique.
        $processusLogistique = CommissionProcessusDefaults::resoudreOuCreer($org->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);

        CommissionRegle::firstOrCreate(
            [
                'organization_id' => $org->id,
                'processus_id' => $processusLogistique->id,
                'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                'scope_type' => CommissionScopeType::GLOBAL->value,
                'statut' => 'active',
            ],
            [
                'libelle' => 'Équipe de livraison — Transfert logistique',
                'scope_id' => null,
                'mode' => CommissionMode::A_REPARTIR->value,
                'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
                'montant' => self::COMMISSION,
                'effective_from' => now()->subDay()->toDateString(),
            ],
        );

        $categories = Categorie::where('organization_id', $org->id)->get();

        foreach ($equipesInternesCreees as ['equipe' => $equipe, 'membres' => $membres]) {
            foreach ($categories as $categorie) {
                foreach ($membres as $m) {
                    EquipeLivraisonPartageCategorie::firstOrCreate(
                        [
                            'equipe_id' => $equipe->id,
                            'processus_id' => $processusLogistique->id,
                            'categorie_id' => $categorie->id,
                            'livreur_id' => $lv($m['telephone'])->id,
                        ],
                        [
                            'part_pourcentage' => 0,
                            'montant_unitaire' => $m['montant'],
                            'effective_from' => now()->subDay(),
                        ],
                    );
                }
            }
        }
    }
}
