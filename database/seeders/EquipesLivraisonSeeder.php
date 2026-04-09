<?php

namespace Database\Seeders;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use Illuminate\Database\Seeder;

/**
 * Cree 3 equipes de livraison avec leurs membres, taux et proprietaire.
 *
 * Regle: taux_proprietaire + SUM(taux membres) = 100 %.
 *
 * | Equipe        | Proprietaire      | Taux prop | Principal         | Taux | Assistant(s)                    | Taux   |
 * |---------------|-------------------|-----------|-------------------|------|---------------------------------|--------|
 * | Nen Dow       | Mamadou BARRY     | 60 %      | Ibrahima CAMARA   | 25 % | Sekou KOUYATE                   | 15 %   |
 * | Auto Dogomet  | Fatoumata DIALLO  | 60 %      | Mariama BAH       | 40 % | -                               | -      |
 * | Baba Ousou    | Mamadou BARRY     | 60 %      | Oumar CAMARA      | 20 % | Abdoulaye SYLLA, Kadiatou TOURE | 15%+5% |
 * | Kaloum Express| Issa TOUNKARA     | 65 %      | Mamadou SOUMAH    | 25 % | Fatoumata KOUROUMA              | 10 %   | (sans véhicule — disponible pour tests)
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

        $equipes = [
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
                    ['telephone' => '+224622000010', 'role' => 'assistant', 'taux' => 5, 'ordre' => 2],
                ],
            ],
            [
                // Équipe sans véhicule — toujours disponible pour la création d'un véhicule
                'nom' => 'Kaloum Express',
                'proprietaire_tel' => '+224621000003', // Issa TOUNKARA
                'membres' => [
                    ['telephone' => '+224622000004', 'role' => 'principal', 'taux' => 25, 'ordre' => 0],
                    ['telephone' => '+224622000005', 'role' => 'assistant', 'taux' => 10, 'ordre' => 1],
                ],
            ],
        ];

        foreach ($equipes as $equipeData) {
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
                $livreur = $lv($m['telephone']);

                EquipeLivreur::updateOrCreate(
                    [
                        'equipe_id' => $equipe->id,
                        'livreur_id' => $livreur->id,
                    ],
                    [
                        'role' => $m['role'],
                        'taux_commission' => $m['taux'],
                        'ordre' => $m['ordre'],
                    ]
                );
            }
        }
    }
}
