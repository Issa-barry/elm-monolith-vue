<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class ClientsInscriptionSeeder extends Seeder
{
    // Préfixe Guinée-Conakry
    private const GN = '+224';

    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $clients = [
            [
                'prenom'          => 'Kadiatou',
                'nom'             => 'DIALLO',
                'telephone'       => self::GN.'620111222',
                'code_phone_pays' => self::GN,
                'code_pays'       => 'GN',
                'pays'            => 'Guinée',
                'ville'           => 'Conakry',
            ],
            [
                'prenom'          => 'Seydou',
                'nom'             => 'KOUYATÉ',
                'telephone'       => self::GN.'655333444',
                'code_phone_pays' => self::GN,
                'code_pays'       => 'GN',
                'pays'            => 'Guinée',
                'ville'           => 'Conakry',
            ],
        ];

        foreach ($clients as $data) {
            Client::updateOrCreate(
                ['telephone' => $data['telephone']],
                array_merge($data, [
                    'organization_id' => $org->id,
                    'user_id'         => null,   // pas encore de compte → éligibles à l'inscription
                    'is_active'       => true,
                ]),
            );
        }

        $this->command->newLine();
        $this->command->info('✓ Clients (sans compte) créés avec succès.');
        $this->command->newLine();
        $this->command->table(
            ['Prénom Nom', 'Téléphone', 'Statut'],
            array_map(fn ($c) => [
                $c['prenom'].' '.$c['nom'],
                $c['telephone'],
                'sans compte (inscription possible)',
            ], $clients),
        );
    }
}
