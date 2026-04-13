<?php

namespace Database\Seeders;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use Illuminate\Database\Seeder;

/**
 * Cree les equipes de livraison avec leurs membres, taux et proprietaire.
 *
 * Regle: taux_proprietaire + SUM(taux membres) = 100 %.
 *
 * Équipes EXTERNES (véhicule appartient à un propriétaire privé) :
 * | Equipe        | Proprietaire      | Taux prop | Principal         | Taux | Assistant(s)                    | Taux   |
 * |---------------|-------------------|-----------|-------------------|------|---------------------------------|--------|
 * | Nen Dow       | Mamadou BARRY     | 60 %      | Ibrahima CAMARA   | 25 % | Sekou KOUYATE                   | 15 %   |
 * | Auto Dogomet  | Fatoumata DIALLO  | 60 %      | Mariama BAH       | 40 % | -                               | -      |
 * | Baba Ousou    | Mamadou BARRY     | 60 %      | Oumar CAMARA      | 20 % | Abdoulaye SYLLA, Kadiatou TOURE | 15%+5% |
 * | Kaloum Express| Issa TOUNKARA     | 65 %      | Mamadou SOUMAH    | 25 % | Fatoumata KOUROUMA              | 10 %   |
 *
 * Équipes INTERNES (véhicule appartient à l'organisation — 100 % aux livreurs) :
 * | Equipe           | Taux prop | Principal          | Taux | Assistant(s)                     | Taux   |
 * |------------------|-----------|--------------------|------|----------------------------------|--------|
 * | ELM Logistique 1 | 0 %       | Boubacar KONATÉ    | 100% | -                                | -      |
 * | ELM Logistique 2 | 0 %       | Aissatou BALDÉ     | 70%  | Thierno SALL                     | 30 %   |
 * | ELM Logistique 3 | 0 %       | Mamadou KEÏTA      | 50%  | Djénabou TRAORÉ, Lamine FOFANA   | 30%+20%|
 */
class EquipesLivraisonSeeder extends Seeder
{
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

        // ── Équipes EXTERNES (propriétaire privé, taux_prop calculé) ─────────

        $equipesExternes = [
            [
                'nom' => 'Nen Dow',
                'proprietaire_tel' => '+224621000001', // Mamadou BARRY
                'membres' => [
                    ['telephone' => '+224622000001', 'role' => 'principal', 'taux' => 25, 'ordre' => 0],
                    ['telephone' => '+224622000002', 'role' => 'assistant', 'taux' => 15, 'ordre' => 1],
                ],
            ],
            [
                'nom' => 'Auto Dogomet',
                'proprietaire_tel' => '+224621000002', // Fatoumata DIALLO
                'membres' => [
                    ['telephone' => '+224622000003', 'role' => 'principal', 'taux' => 40, 'ordre' => 0],
                ],
            ],
            [
                'nom' => 'Baba Ousou',
                'proprietaire_tel' => '+224621000001', // Mamadou BARRY
                'membres' => [
                    ['telephone' => '+224622000008', 'role' => 'principal', 'taux' => 20, 'ordre' => 0],
                    ['telephone' => '+224622000009', 'role' => 'assistant', 'taux' => 15, 'ordre' => 1],
                    ['telephone' => '+224622000010', 'role' => 'assistant', 'taux' => 5,  'ordre' => 2],
                ],
            ],
            [
                // Sans véhicule — disponible pour tests
                'nom' => 'Kaloum Express',
                'proprietaire_tel' => '+224621000003', // Issa TOUNKARA
                'membres' => [
                    ['telephone' => '+224622000004', 'role' => 'principal', 'taux' => 25, 'ordre' => 0],
                    ['telephone' => '+224622000005', 'role' => 'assistant', 'taux' => 10, 'ordre' => 1],
                ],
            ],
        ];

        foreach ($equipesExternes as $equipeData) {
            $proprietaire = $prop($equipeData['proprietaire_tel']);
            $sommeMembres = array_sum(array_column($equipeData['membres'], 'taux'));

            $equipe = EquipeLivraison::updateOrCreate(
                ['nom' => $equipeData['nom'], 'organization_id' => $org->id],
                [
                    'is_active' => true,
                    'proprietaire_id' => $proprietaire->id,
                    'taux_commission_proprietaire' => 100 - $sommeMembres,
                ]
            );

            foreach ($equipeData['membres'] as $m) {
                EquipeLivreur::updateOrCreate(
                    ['equipe_id' => $equipe->id, 'livreur_id' => $lv($m['telephone'])->id],
                    ['role' => $m['role'], 'taux_commission' => $m['taux'], 'ordre' => $m['ordre']]
                );
            }
        }

        // ── Équipes INTERNES (véhicule org — 100 % aux livreurs, pas de propriétaire) ──

        $equipesInternes = [
            [
                'nom' => 'ELM Logistique 1',
                'membres' => [
                    ['telephone' => '+224622000011', 'role' => 'principal', 'taux' => 100, 'ordre' => 0],
                ],
            ],
            [
                'nom' => 'ELM Logistique 2',
                'membres' => [
                    ['telephone' => '+224622000012', 'role' => 'principal', 'taux' => 70, 'ordre' => 0],
                    ['telephone' => '+224622000013', 'role' => 'assistant', 'taux' => 30, 'ordre' => 1],
                ],
            ],
            [
                'nom' => 'ELM Logistique 3',
                'membres' => [
                    ['telephone' => '+224622000014', 'role' => 'principal', 'taux' => 50, 'ordre' => 0],
                    ['telephone' => '+224622000015', 'role' => 'assistant', 'taux' => 30, 'ordre' => 1],
                    ['telephone' => '+224622000016', 'role' => 'assistant', 'taux' => 20, 'ordre' => 2],
                ],
            ],
        ];

        foreach ($equipesInternes as $equipeData) {
            $equipe = EquipeLivraison::updateOrCreate(
                ['nom' => $equipeData['nom'], 'organization_id' => $org->id],
                [
                    'is_active' => true,
                    'proprietaire_id' => null, // véhicule interne : pas de propriétaire
                    'taux_commission_proprietaire' => 0,
                ]
            );

            foreach ($equipeData['membres'] as $m) {
                EquipeLivreur::updateOrCreate(
                    ['equipe_id' => $equipe->id, 'livreur_id' => $lv($m['telephone'])->id],
                    ['role' => $m['role'], 'taux_commission' => $m['taux'], 'ordre' => $m['ordre']]
                );
            }
        }
    }
}
