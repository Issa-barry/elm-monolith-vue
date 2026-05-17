<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Services\MatriculeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class ComptableCommercialeSeeder extends Seeder
{
    private const PASSWORD = 'Staff@2025';

    public function run(): void
    {
        $org = Organization::firstOrCreate(
            ['slug' => 'elm'],
            ['name' => 'Eau la maman', 'is_active' => true]
        );

        $comptableRole = Role::firstOrCreate(['name' => 'comptable', 'guard_name' => 'web']);
        $commercialeRole = Role::firstOrCreate(['name' => 'commerciale', 'guard_name' => 'web']);

        $users = [
            [
                'prenom' => 'Aminata',
                'nom' => 'DIALLO',
                'telephone' => '+224623900010',
                'role' => $comptableRole,
            ],
            [
                'prenom' => 'Alpha Oumar',
                'nom' => 'CAMARA',
                'telephone' => '+224623900011',
                'role' => $commercialeRole,
            ],
        ];

        foreach ($users as $data) {
            $user = User::updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'prenom' => $data['prenom'],
                    'nom' => $data['nom'],
                ],
                [
                    'telephone' => $data['telephone'],
                    'email' => null,
                    'password' => Hash::make(self::PASSWORD),
                    'pays' => 'Guinée',
                    'code_pays' => 'GN',
                    'code_phone_pays' => '+224',
                    'is_active' => true,
                ]
            );

            $user->syncRoles([$data['role']->name]);
            app(MatriculeService::class)->assignForUser($user);
        }

        $this->command->newLine();
        $this->command->info('✓ Comptable et commerciale créés avec succès.');
        $this->command->newLine();
        $this->command->table(
            ['Prénom Nom', 'Téléphone', 'Rôle', 'Mot de passe'],
            [
                ['Aminata DIALLO', '+224623900010', 'comptable', self::PASSWORD],
                ['Alpha Oumar CAMARA', '+224623900011', 'commerciale', self::PASSWORD],
            ]
        );
    }
}
