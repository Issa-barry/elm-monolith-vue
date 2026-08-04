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
            'ville' => 'Conakry',
            'quartier' => 'Matoto',
            'telephone' => '+224664039160',
        ]);

        $sitesRattaches = [
            [
                'nom' => 'Cba (Lansanaya, Kountia)',
                'type' => SiteType::USINE->value,
                'ville' => 'Conakry',
                'quartier' => 'Kountia',
                'telephone' => '+224626078393',
            ],
            [
                'nom' => 'Lambanyi',
                'type' => SiteType::DEPOT->value,
                'ville' => 'Conakry',
                'quartier' => 'Lambagny',
                'telephone' => '+224622671016',
            ],
            [
                'nom' => 'Cimenterie',
                'type' => SiteType::DEPOT->value,
                'ville' => 'Conakry',
                'quartier' => 'Cimenteri',
                'telephone' => '+224622854863',
            ],
            [
                'nom' => 'Kouria',
                'type' => SiteType::USINE->value,
                'ville' => 'Coya',
                'quartier' => 'Kaka',
                'telephone' => '+224626641466',
            ],
            [
                'nom' => 'Sonfonia',
                'type' => SiteType::DEPOT->value,
                'ville' => 'Conakry',
                'quartier' => 'Sonfonia',
                'telephone' => '+224624300206',
            ],
            [
                'nom' => 'Tombolia',
                'type' => SiteType::DEPOT->value,
                'ville' => 'Conakry',
                'quartier' => 'Tombolia',
                'telephone' => '+224626577425',
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
            'ville' => $data['ville'],
            'pays' => 'Guinee',
            'quartier' => $data['quartier'],
            'parent_id' => $parentId,
        ]);

        if (! $site->exists || empty($site->telephone)) {
            $site->telephone = $data['telephone'];
        }

        $site->save();

        return $site;
    }
}
