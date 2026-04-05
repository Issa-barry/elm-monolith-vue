<?php

namespace Database\Seeders;

use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Crée 5 équipes de livraison avec leurs membres et taux.
 *
 * Règle : SUM(taux membres) sera connu ici.
 * Le taux propriétaire = 100 - SUM(taux membres) est défini dans VehiculesSeeder.
 *
 * | Équipe      | Principal         | Taux | Assistant(s)                        | Taux | Σ équipe |
 * |-------------|-------------------|------|-------------------------------------|------|----------|
 * | Nord        | Ibrahima CAMARA   |  5 % | Sékou KOUYATÉ                       |  3 % |    8 %   |
 * | Sud         | Mariama BAH       |  6 % | —                                   |  —   |    6 %   |
 * | Est         | Mamadou SOUMAH    |  5 % | Fatoumata KOUROUMA                  |  3 % |    8 %   |
 * | Ouest       | Boubacar DIALLO   |  7 % | Alpha BARRY                         |  2 % |    9 %   |
 * | Centre      | Oumar CAMARA      |  5 % | Abdoulaye SYLLA, Kadiatou TOURÉ     | 3+2 % |  10 %   |
 */
class EquipesLivraisonSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        // Récupère les livreurs par téléphone (créés par LivreursSeeder)
        $lv = fn (string $tel) => Livreur::where('telephone', $tel)
            ->where('organization_id', $org->id)
            ->firstOrFail();

        $ibrahima   = $lv('+224622000001');
        $sekou      = $lv('+224622000002');
        $mariama    = $lv('+224622000003');
        $mamadouS   = $lv('+224622000004');
        $fatoumataK = $lv('+224622000005');
        $boubacar   = $lv('+224622000006');
        $alpha      = $lv('+224622000007');
        $oumar      = $lv('+224622000008');
        $abdoulaye  = $lv('+224622000009');
        $kadiatou   = $lv('+224622000010');

        $equipes = [
            [
                'nom'     => 'Équipe Nord',
                'membres' => [
                    ['livreur' => $ibrahima,   'role' => 'principal', 'taux' => 5.00, 'ordre' => 0],
                    ['livreur' => $sekou,      'role' => 'assistant', 'taux' => 3.00, 'ordre' => 1],
                ],
            ],
            [
                'nom'     => 'Équipe Sud',
                'membres' => [
                    ['livreur' => $mariama,    'role' => 'principal', 'taux' => 6.00, 'ordre' => 0],
                ],
            ],
            [
                'nom'     => 'Équipe Est',
                'membres' => [
                    ['livreur' => $mamadouS,   'role' => 'principal', 'taux' => 5.00, 'ordre' => 0],
                    ['livreur' => $fatoumataK, 'role' => 'assistant', 'taux' => 3.00, 'ordre' => 1],
                ],
            ],
            [
                'nom'     => 'Équipe Ouest',
                'membres' => [
                    ['livreur' => $boubacar,   'role' => 'principal', 'taux' => 7.00, 'ordre' => 0],
                    ['livreur' => $alpha,      'role' => 'assistant', 'taux' => 2.00, 'ordre' => 1],
                ],
            ],
            [
                'nom'     => 'Équipe Centre',
                'membres' => [
                    ['livreur' => $oumar,      'role' => 'principal', 'taux' => 5.00, 'ordre' => 0],
                    ['livreur' => $abdoulaye,  'role' => 'assistant', 'taux' => 3.00, 'ordre' => 1],
                    ['livreur' => $kadiatou,   'role' => 'assistant', 'taux' => 2.00, 'ordre' => 2],
                ],
            ],
        ];

        foreach ($equipes as $equipeData) {
            $equipe = EquipeLivraison::firstOrCreate(
                ['nom' => $equipeData['nom'], 'organization_id' => $org->id],
                ['is_active' => true]
            );

            // Idempotent : ne recrée pas les membres si déjà présents
            if ($equipe->membres()->count() === 0) {
                foreach ($equipeData['membres'] as $m) {
                    EquipeLivreur::create([
                        'equipe_id'        => $equipe->id,
                        'livreur_id'       => $m['livreur']->id,
                        'role'             => $m['role'],
                        'taux_commission'  => $m['taux'],
                        'ordre'            => $m['ordre'],
                    ]);
                }
            }
        }
    }
}
