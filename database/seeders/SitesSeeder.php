<?php

namespace Database\Seeders;

use App\Enums\SiteStatut;
use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Database\Seeder;

class SitesSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm-demo')->firstOrFail();

        // Créer le siège en premier (parent)
        $siege = Site::firstOrCreate(
            ['nom' => 'Matoto', 'organization_id' => $org->id],
            [
                'nom'             => 'Matoto',
                'type'            => SiteType::SIEGE->value,
                'statut'          => SiteStatut::ACTIVE->value,
                'ville'           => 'Conakry',
                'pays'            => 'Guinée',
                'localisation'    => 'Matoto, Conakry',
                'telephone'       => '+224620000002',
                'organization_id' => $org->id,
            ]
        );

        // Kouria rattaché au siège
        Site::firstOrCreate(
            ['nom' => 'Kouria', 'organization_id' => $org->id],
            [
                'nom'             => 'Kouria',
                'type'            => SiteType::USINE->value,
                'statut'          => SiteStatut::ACTIVE->value,
                'ville'           => 'Conakry',
                'pays'            => 'Guinée',
                'localisation'    => 'Kouria, Conakry',
                'telephone'       => '+224620000001',
                'parent_id'       => $siege->id,
                'organization_id' => $org->id,
            ]
        );
    }
}
