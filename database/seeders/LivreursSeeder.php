<?php

namespace Database\Seeders;

use App\Models\Livreur;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class LivreursSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $livreurs = [
            [
                'nom'             => 'BAH',
                'prenom'          => 'Mariama',
                'email'           => 'mariama.bah@example.com',
                'telephone'       => '+224622000003',
                'code_phone_pays' => '+224',
                'code_pays'       => 'GN',
                'pays'            => 'Guinée',
                'ville'           => 'Conakry',
                'adresse'         => 'Kaloum, Conakry',
                'is_active'       => true,
            ],
            [
                'nom'             => 'CAMARA',
                'prenom'          => 'Ibrahima',
                'email'           => null,
                'telephone'       => '+224622000001',
                'code_phone_pays' => '+224',
                'code_pays'       => 'GN',
                'pays'            => 'Guinée',
                'ville'           => 'Conakry',
                'adresse'         => null,
                'is_active'       => true,
            ],
            [
                'nom'             => 'KOUYATÉ',
                'prenom'          => 'Sékou',
                'email'           => null,
                'telephone'       => '+224622000002',
                'code_phone_pays' => '+224',
                'code_pays'       => 'GN',
                'pays'            => 'Guinée',
                'ville'           => 'Conakry',
                'adresse'         => null,
                'is_active'       => true,
            ],
        ];

        foreach ($livreurs as $data) {
            Livreur::firstOrCreate(
                ['telephone' => $data['telephone'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id]
            );
        }
    }
}
