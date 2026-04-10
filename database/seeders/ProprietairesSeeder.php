<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Proprietaire;
use Illuminate\Database\Seeder;

class ProprietairesSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $proprietaires = [
            [
                'nom' => 'BARRY',
                'prenom' => 'Mamadou',
                'email' => 'mamadou.barry@elm.gn',
                'telephone' => '+224621000001',
                'adresse' => 'Kaloum',
                'ville' => 'Conakry',
                'is_active' => true,
            ],
            [
                'nom' => 'DIALLO',
                'prenom' => 'Fatoumata',
                'email' => 'fatoumata.diallo@elm.gn',
                'telephone' => '+224621000002',
                'adresse' => 'Ratoma',
                'ville' => 'Conakry',
                'is_active' => true,
            ],
            [
                'nom' => 'TOUNKARA',
                'prenom' => 'Issa',
                'email' => 'issa.tounkara@elm.gn',
                'telephone' => '+224621000003',
                'adresse' => 'Matoto',
                'ville' => 'Conakry',
                'is_active' => true,
            ],
            [
                'nom' => 'CONDÉ',
                'prenom' => 'Saran',
                'email' => 'saran.conde@elm.gn',
                'telephone' => '+224621000004',
                'adresse' => 'Dixinn',
                'ville' => 'Conakry',
                'is_active' => true,
            ],
        ];

        foreach ($proprietaires as $data) {
            Proprietaire::firstOrCreate(
                ['telephone' => $data['telephone'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id]
            );
        }
    }
}
