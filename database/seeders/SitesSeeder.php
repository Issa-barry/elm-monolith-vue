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
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $matoto = $this->upsertSite($org->id, [
            'nom' => 'Matoto',
            'type' => SiteType::SIEGE->value,
            'localisation' => 'Matoto',
        ]);

        $sitesRattaches = [
            [
                'nom' => 'Lansanaya',
                'type' => SiteType::USINE->value,
                'localisation' => 'Lansanaya Barrage',
            ],
            [
                'nom' => 'Lambagny',
                'type' => SiteType::AGENCE->value,
                'localisation' => 'Lambagny carrefour canadien',
            ],
            [
                'nom' => 'Dabompa',
                'type' => SiteType::DEPOT->value,
                'localisation' => 'Tamisso',
            ],
        ];

        foreach ($sitesRattaches as $siteData) {
            $this->upsertSite($org->id, $siteData, $matoto->id);
        }
    }

    /**
     * Create/update site and keep existing phone if already present.
     */
    private function upsertSite(string $organizationId, array $data, ?string $parentId = null): Site
    {
        $site = Site::firstOrNew([
            'organization_id' => $organizationId,
            'nom' => $data['nom'],
        ]);

        $site->fill([
            'organization_id' => $organizationId,
            'nom' => $data['nom'],
            'type' => $data['type'],
            'statut' => SiteStatut::ACTIVE->value,
            'ville' => 'Conakry',
            'pays' => 'Guinee',
            'localisation' => $data['localisation'],
            'quartier' => $data['nom'],
            'parent_id' => $parentId,
        ]);

        if (! $site->exists || empty($site->telephone)) {
            $site->telephone = $this->randomGnPhone();
        }

        $site->save();

        return $site;
    }

    private function randomGnPhone(): string
    {
        return '+2246'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
}
