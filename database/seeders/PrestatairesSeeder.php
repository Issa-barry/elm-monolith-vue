<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Personne;
use App\Models\Prestataire;
use Illuminate\Database\Seeder;

class PrestatairesSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $prestataires = [
            [
                'nom' => 'Diallo',
                'prenom' => 'Mamadou',
                'email' => 'mamadou.diallo@example.com',
                'phone' => '+224620000001',
                'code_phone_pays' => '+224',
                'code_pays' => 'GN',
                'pays' => 'Guinée',
                'ville' => 'Conakry',
                'adresse' => null,
                'type' => 'machiniste',
                'notes' => null,
                'is_active' => true,
            ],
            [
                'nom' => 'Camara',
                'prenom' => 'Ibrahima',
                'email' => 'ibrahima.camara@example.com',
                'phone' => '+224620000002',
                'code_phone_pays' => '+224',
                'code_pays' => 'GN',
                'pays' => 'Guinée',
                'ville' => 'Conakry',
                'adresse' => null,
                'type' => 'machiniste',
                'notes' => null,
                'is_active' => true,
            ],
        ];

        foreach ($prestataires as $data) {
            $personne = Personne::resoudreOuCreer($org->id, [
                'nom' => $data['nom'],
                'prenom' => $data['prenom'],
                'telephone' => $data['phone'],
                'email' => $data['email'],
                'pays' => $data['pays'],
                'code_pays' => $data['code_pays'],
                'code_phone_pays' => $data['code_phone_pays'],
                'ville' => $data['ville'],
                'adresse' => $data['adresse'],
            ]);

            Prestataire::firstOrCreate(
                ['personne_id' => $personne->id],
                [
                    'organization_id' => $org->id,
                    'personne_id' => $personne->id,
                    'type' => $data['type'],
                    'notes' => $data['notes'],
                    'is_active' => $data['is_active'],
                ]
            );
        }
    }
}
