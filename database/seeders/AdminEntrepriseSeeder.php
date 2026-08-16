<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Services\MatriculeService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class AdminEntrepriseSeeder extends Seeder
{
    private const PASSWORD = 'Staff@2025';

    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();
        $matoto = Site::where('organization_id', $org->id)->where('nom', 'Matoto')->firstOrFail();
        $adminEntrepriseRole = Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);

        $users = [
            [
                'prenom' => 'Ibrahima Strasbourg',
                'nom' => 'BARRY',
                'telephone' => '+33751252309',
                'code_pays' => 'FR',
            ],
            [
                'prenom' => 'Aboubacar Data',
                'nom' => 'BARRY',
                'telephone' => '+33666425820',
                'code_pays' => 'FR',
            ],
        ];

        foreach ($users as $data) {
            $personne = Personne::resoudreOuCreer($org->id, [
                'prenom' => $data['prenom'],
                'nom' => $data['nom'],
                'telephone' => $data['telephone'],
                'code_pays' => $data['code_pays'],
                'pays' => 'France',
                'code_phone_pays' => '+33',
            ]);

            $user = User::updateOrCreate(
                ['personne_id' => $personne->id],
                ['password' => self::PASSWORD, 'organization_id' => $org->id, 'is_active' => true]
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

            $user->syncRoles([$adminEntrepriseRole->name]);
            app(MatriculeService::class)->assignForUser($user);

            $user->sites()->syncWithoutDetaching([
                $matoto->id => ['role' => 'employe', 'is_default' => true],
            ]);
        }

        $this->command->newLine();
        $this->command->info('✓ Comptes admin_entreprise créés avec succès.');
        $this->command->newLine();
        $this->command->table(
            ['Prénom Nom', 'Téléphone', 'Rôle', 'Site', 'Mot de passe'],
            [
                ['Ibrahima Strasbourg BARRY', '+33751252309', 'admin_entreprise', 'Matoto', self::PASSWORD],
                ['Aboubacar Data BARRY', '+33666425820', 'admin_entreprise', 'Matoto', self::PASSWORD],
            ]
        );
    }
}
