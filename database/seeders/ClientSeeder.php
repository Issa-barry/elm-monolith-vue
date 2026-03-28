<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ClientSeeder extends Seeder
{
    private const PASSWORD = 'Staff@2025';

    // Préfixe Guinée-Conakry
    private const GN = '+224';

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);

        $clients = [
            ['prenom' => 'Fatoumata', 'nom' => 'BALDE',   'telephone' => self::GN . '622345678'],
            ['prenom' => 'Mariama',   'nom' => 'SOW',     'telephone' => self::GN . '664123456'],
            ['prenom' => 'Ibrahima',  'nom' => 'DIALLO',  'telephone' => self::GN . '621987654'],
            ['prenom' => 'Aissatou',  'nom' => 'BARRY',   'telephone' => self::GN . '657234567'],
            ['prenom' => 'Mamadou',   'nom' => 'CAMARA',  'telephone' => self::GN . '628765432'],
        ];

        foreach ($clients as $data) {
            $user = User::firstOrCreate(
                ['telephone' => $data['telephone']],
                [
                    'prenom'   => $data['prenom'],
                    'nom'      => $data['nom'],
                    'email'    => null,
                    'password' => Hash::make(self::PASSWORD),
                ]
            );

            $user->syncRoles(['client']);
        }

        $this->command->newLine();
        $this->command->info('✓ Clients créés avec succès.');
        $this->command->newLine();
        $this->command->table(
            ['Prénom Nom', 'Téléphone', 'Rôle', 'Mot de passe'],
            array_map(fn ($c) => [
                $c['prenom'] . ' ' . $c['nom'],
                $c['telephone'],
                'client',
                self::PASSWORD,
            ], $clients)
        );
    }
}
