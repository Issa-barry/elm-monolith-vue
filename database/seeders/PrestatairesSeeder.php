<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Prestataire;
use Illuminate\Database\Seeder;

class PrestatairesSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $prestataires = [
            [
                'nom'             => 'Diallo',
                'prenom'          => 'Mamadou',
                'raison_sociale'  => null,
                'email'           => 'mamadou.diallo@example.com',
                'phone'           => '+224620000001',
                'code_phone_pays' => '+224',
                'code_pays'       => 'GN',
                'pays'            => 'Guinée',
                'ville'           => 'Conakry',
                'adresse'         => null,
                'type'            => 'machiniste',
                'notes'           => null,
                'is_active'       => true,
            ],
            [
                'nom'             => 'Camara',
                'prenom'          => 'Ibrahima',
                'raison_sociale'  => null,
                'email'           => 'ibrahima.camara@example.com',
                'phone'           => '+224620000002',
                'code_phone_pays' => '+224',
                'code_pays'       => 'GN',
                'pays'            => 'Guinée',
                'ville'           => 'Conakry',
                'adresse'         => null,
                'type'            => 'machiniste',
                'notes'           => null,
                'is_active'       => true,
            ],
        ];

        foreach ($prestataires as $data) {
            Prestataire::firstOrCreate(
                ['email' => $data['email']],
                [...$data, 'organization_id' => $org->id]
            );
        }
    }
}
