<?php

namespace Database\Seeders;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Cree 3 equipes de livraison avec leurs membres et taux.
 *
 * Regle: SUM(taux membres) est defini ici.
 * Le taux proprietaire = 100 - SUM(taux membres) est defini dans VehiculesSeeder.
 *
 * | Equipe        | Principal         | Taux | Assistant(s)                   | Taux   | Somme |
 * |---------------|-------------------|------|--------------------------------|--------|-------|
 * | Nen Dow       | Ibrahima CAMARA   | 25 % | Sekou KOUYATE                  | 15 %   | 40 %  |
 * | Auto Dogomet  | Mariama BAH       | 40 % | -                              | -      | 40 %  |
 * | Baba Ousou    | Oumar CAMARA      | 20 % | Abdoulaye SYLLA, Kadiatou TOURE| 15%+5% | 40 %  |
 */
class EquipesLivraisonSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        // Recupere les livreurs par telephone (crees par LivreursSeeder).
        $lv = fn (string $tel) => Livreur::query()
            ->where('telephone', $tel)
            ->where('organization_id', $org->id)
            ->firstOrFail();

        $equipes = [
            [
                'nom'     => 'Nen Dow',
                'membres' => [
                    ['telephone' => '+224622000001', 'role' => 'principal', 'taux' => 25, 'ordre' => 0],
                    ['telephone' => '+224622000002', 'role' => 'assistant', 'taux' => 15, 'ordre' => 1],
                ],
            ],
            [
                'nom'     => 'Auto Dogomet',
                'membres' => [
                    ['telephone' => '+224622000003', 'role' => 'principal', 'taux' => 40, 'ordre' => 0],
                ],
            ],
            [
                'nom'     => 'Baba Ousou',
                'membres' => [
                    ['telephone' => '+224622000008', 'role' => 'principal', 'taux' => 20, 'ordre' => 0],
                    ['telephone' => '+224622000009', 'role' => 'assistant', 'taux' => 15, 'ordre' => 1],
                    ['telephone' => '+224622000010', 'role' => 'assistant', 'taux' => 5, 'ordre' => 2],
                ],
            ],
        ];

        foreach ($equipes as $equipeData) {
            $equipe = EquipeLivraison::updateOrCreate(
                ['nom' => $equipeData['nom'], 'organization_id' => $org->id],
                ['is_active' => true]
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
