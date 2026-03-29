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
                'nom' => 'Barry',
                'prenom' => 'Mamadou',
                'email' => 'mamadou.barry@example.com',
                'telephone' => '+224621000001',
                'adresse' => 'Kaloum, Conakry',
                'is_active' => true,
            ],
            [
                'nom' => 'Diallo',
                'prenom' => 'Fatoumata',
                'email' => 'fatoumata.diallo@example.com',
                'telephone' => '+224621000002',
                'adresse' => 'Ratoma, Conakry',
                'is_active' => true,
            ],
        ];

        foreach ($proprietaires as $data) {
            Proprietaire::firstOrCreate(
                ['email' => $data['email'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id]
            );
        }
    }
}
