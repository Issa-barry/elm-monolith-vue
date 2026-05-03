<?php

namespace Database\Seeders;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use Illuminate\Database\Seeder;

/**
 * Cree les equipes de livraison avec leurs membres et la répartition par montant/pack.
 *
 * Règle: somme(montants membres) + montant_proprietaire = commission_unitaire_par_pack
 *        taux = montant / commission * 100 (calculé et stocké à la sauvegarde)
 *
 * Équipes EXTERNES (commission 200 GNF/pack) :
 * | Equipe        | Proprietaire      | Prop GNF | Chauffeur         | GNF | Convoyeur(s)          | GNF   |
 * |---------------|-------------------|----------|-------------------|-----|-----------------------|-------|
 * | Nen Dow       | Amadou DIALLO     | 120      | Ibrahima CAMARA   | 50  | Sekou KOUYATE         | 30    |
 * | Auto Dogomet  | Fatoumata DIALLO  | 120      | Mariama BAH       | 80  | -                     | -     |
 * | Baba Ousou    | Amadou DIALLO     | 120      | Oumar CAMARA      | 40  | A. SYLLA, K. TOURE    | 30+10 |
 * | Kaloum Express| Issa TOUNKARA     | 130      | Mamadou SOUMAH    | 50  | Fatoumata KOUROUMA    | 20    |
 *
 * Équipes INTERNES (commission 200 GNF/pack — 100 % aux membres) :
 * | Equipe           | Prop GNF | Chauffeur          | GNF | Convoyeur(s)                  | GNF    |
 * |------------------|---------|--------------------|-----|-------------------------------|--------|
 * | ELM Logistique 1 | 0       | Boubacar KONATÉ    | 200 | -                             | -      |
 * | ELM Logistique 2 | 0       | Aissatou BALDÉ     | 140 | Thierno SALL                  | 60     |
 * | ELM Logistique 3 | 0       | Mamadou KEÏTA      | 100 | Djénabou TRAORÉ, Lamine FOFANA| 60+40  |
 */
class EquipesLivraisonSeeder extends Seeder
{
    private const COMMISSION = 200; // GNF par pack

    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $lv = fn (string $tel) => Livreur::query()
            ->where('telephone', $tel)
            ->where('organization_id', $org->id)
            ->firstOrFail();

        $prop = fn (string $tel) => Proprietaire::query()
            ->where('telephone', $tel)
            ->where('organization_id', $org->id)
            ->firstOrFail();

        // ── Équipes EXTERNES ──────────────────────────────────────────────────

        $equipesExternes = [
            [
                'nom' => 'Nen Dow',
                'proprietaire_tel' => '+33754158797',
                'membres' => [
                    ['telephone' => '+224622000001', 'role' => 'chauffeur', 'montant' => 50, 'ordre' => 0],
                    ['telephone' => '+224622000002', 'role' => 'convoyeur', 'montant' => 30, 'ordre' => 1],
                ],
            ],
            [
                'nom' => 'Auto Dogomet',
                'proprietaire_tel' => '+224621000002',
                'membres' => [
                    ['telephone' => '+224622000003', 'role' => 'chauffeur', 'montant' => 80, 'ordre' => 0],
                ],
            ],
            [
                'nom' => 'Baba Ousou',
                'proprietaire_tel' => '+33754158797',
                'membres' => [
                    ['telephone' => '+224622000008', 'role' => 'chauffeur', 'montant' => 40, 'ordre' => 0],
                    ['telephone' => '+224622000009', 'role' => 'convoyeur', 'montant' => 30, 'ordre' => 1],
                    ['telephone' => '+224622000010', 'role' => 'convoyeur', 'montant' => 10, 'ordre' => 2],
                ],
            ],
            [
                'nom' => 'Kaloum Express',
                'proprietaire_tel' => '+224621000003',
                'membres' => [
                    ['telephone' => '+224622000004', 'role' => 'chauffeur', 'montant' => 50, 'ordre' => 0],
                    ['telephone' => '+224622000005', 'role' => 'convoyeur', 'montant' => 20, 'ordre' => 1],
                ],
            ],
        ];

        foreach ($equipesExternes as $equipeData) {
            $proprietaire = $prop($equipeData['proprietaire_tel']);
            $sommeMembres = array_sum(array_column($equipeData['membres'], 'montant'));
            $montantProp = self::COMMISSION - $sommeMembres;
            $tauxProp = round($montantProp / self::COMMISSION * 100, 2);

            $equipe = EquipeLivraison::updateOrCreate(
                ['nom' => $equipeData['nom'], 'organization_id' => $org->id],
                [
                    'is_active' => true,
                    'proprietaire_id' => $proprietaire->id,
                    'commission_unitaire_par_pack' => self::COMMISSION,
                    'montant_par_pack_proprietaire' => $montantProp,
                    'taux_commission_proprietaire' => $tauxProp,
                ]
            );

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
        }

        // ── Équipes INTERNES ──────────────────────────────────────────────────

        $equipesInternes = [
            [
                'nom' => 'ELM Logistique 1',
                'membres' => [
                    ['telephone' => '+224622000011', 'role' => 'chauffeur', 'montant' => 200, 'ordre' => 0],
                ],
            ],
            [
                'nom' => 'ELM Logistique 2',
                'membres' => [
                    ['telephone' => '+224622000012', 'role' => 'chauffeur', 'montant' => 140, 'ordre' => 0],
                    ['telephone' => '+224622000013', 'role' => 'convoyeur', 'montant' => 60, 'ordre' => 1],
                ],
            ],
            [
                'nom' => 'ELM Logistique 3',
                'membres' => [
                    ['telephone' => '+224622000014', 'role' => 'chauffeur', 'montant' => 100, 'ordre' => 0],
                    ['telephone' => '+224622000015', 'role' => 'convoyeur', 'montant' => 60, 'ordre' => 1],
                    ['telephone' => '+224622000016', 'role' => 'convoyeur', 'montant' => 40, 'ordre' => 2],
                ],
            ],
        ];

        foreach ($equipesInternes as $equipeData) {
            $equipe = EquipeLivraison::updateOrCreate(
                ['nom' => $equipeData['nom'], 'organization_id' => $org->id],
                [
                    'is_active' => true,
                    'proprietaire_id' => null,
                    'commission_unitaire_par_pack' => self::COMMISSION,
                    'montant_par_pack_proprietaire' => null,
                    'taux_commission_proprietaire' => 0,
                ]
            );

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
        }
    }
}
