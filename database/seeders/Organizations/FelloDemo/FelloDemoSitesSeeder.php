<?php

namespace Database\Seeders\Organizations\FelloDemo;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Database\Seeder;

/**
 * Deux boutiques pour la démo — le projet n'a pas de type "boutique" dans
 * SiteType (siege/usine/depot/agence uniquement) : AGENCE est le plus
 * proche d'un point de vente.
 */
class FelloDemoSitesSeeder extends Seeder
{
    private const SITES = [
        ['nom' => 'Boutique Madina', 'ville' => 'Madina'],
        ['nom' => 'Boutique Cosa', 'ville' => 'Cosa'],
    ];

    public function run(): void
    {
        $org = Organization::where('slug', 'fello-demo')->firstOrFail();

        foreach (self::SITES as $data) {
            Site::firstOrCreate(
                ['organization_id' => $org->id, 'nom' => $data['nom']],
                [
                    'type' => SiteType::AGENCE,
                    'ville' => $data['ville'],
                    'pays' => 'Guinée',
                    'localisation' => $data['ville'].', Conakry',
                ]
            );
        }

        $this->command->info('✓ 2 sites créés : Boutique Madina, Boutique Cosa.');
    }
}
