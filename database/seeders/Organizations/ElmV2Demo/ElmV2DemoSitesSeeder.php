<?php

namespace Database\Seeders\Organizations\ElmV2Demo;

use App\Models\Organization;
use App\Models\Site;
use Illuminate\Database\Seeder;

class ElmV2DemoSitesSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm-v2-demo')->firstOrFail();

        Site::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Siège V2 Demo'],
            ['type' => 'depot', 'localisation' => 'Conakry']
        );

        $this->command->info('✓ Site « Siège V2 Demo » prêt.');
    }
}
