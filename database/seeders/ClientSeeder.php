<?php

namespace Database\Seeders;

use App\Models\Personne;
use App\Models\User;
use App\Models\UserAuthIdentity;
use Illuminate\Database\Seeder;
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

        $clientRole = Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);

        $clients = [
            ['prenom' => 'Fatoumata', 'nom' => 'BALDE',   'telephone' => self::GN.'622345678'],
            ['prenom' => 'Mariama',   'nom' => 'SOW',     'telephone' => self::GN.'664123456'],
            ['prenom' => 'Ibrahima',  'nom' => 'DIALLO',  'telephone' => self::GN.'621987654'],
            ['prenom' => 'Aissatou',  'nom' => 'BARRY',   'telephone' => self::GN.'657234567'],
            ['prenom' => 'Mamadou',   'nom' => 'CAMARA',  'telephone' => self::GN.'628765432'],
        ];

        foreach ($clients as $data) {
            // Pas d'organisation : compte client "à la volée", cf. RegistrationService.
            $personne = Personne::firstOrCreate(
                ['organization_id' => null, 'telephone_normalise' => Personne::normaliserTelephone($data['telephone'])],
                ['prenom' => $data['prenom'], 'nom' => $data['nom'], 'telephone' => $data['telephone']]
            );

            // updateOrCreate garantit que le mot de passe est toujours réinitialisé
            // lors d'un re-seed, même si le compte existe déjà.
            $user = User::updateOrCreate(
                ['personne_id' => $personne->id],
                ['password' => self::PASSWORD]
            );
            $user->authIdentities()->updateOrCreate(
                ['type' => UserAuthIdentity::TYPE_TELEPHONE],
                [
                    'value' => $data['telephone'],
                    'normalized_value' => Personne::normaliserTelephone($data['telephone']),
                    'verified_at' => now(),
                    'is_primary' => true,
                ]
            );

            // Idempotent: n'insère pas de doublon si le rôle est déjà attaché.
            $user->roles()->syncWithoutDetaching([$clientRole->id]);
        }

        $this->command->newLine();
        $this->command->info('✓ Clients créés avec succès.');
        $this->command->newLine();
        $this->command->table(
            ['Prénom Nom', 'Téléphone', 'Rôle', 'Mot de passe'],
            array_map(fn ($c) => [
                $c['prenom'].' '.$c['nom'],
                $c['telephone'],
                'client',
                self::PASSWORD,
            ], $clients)
        );
    }
}
