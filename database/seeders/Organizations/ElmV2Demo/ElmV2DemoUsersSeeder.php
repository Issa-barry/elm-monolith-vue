<?php

namespace Database\Seeders\Organizations\ElmV2Demo;

use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Services\MatriculeService;
use Illuminate\Database\Seeder;

/**
 * Compte staff de démo unique pour "Eau La Maman V2 Demo" (admin_entreprise
 * — permissions vente/véhicules/équipes/comptabilité complètes, cf.
 * RolesAndPermissionsSeeder). Numéro réservé (+224600000201), distinct des
 * plages déjà utilisées par "elm" (comptes E2E par défaut) et "fello-demo"
 * (+224600000101/102).
 */
class ElmV2DemoUsersSeeder extends Seeder
{
    public const TELEPHONE = '+224600000201';

    public const PASSWORD = 'ElmV2Demo@2025';

    public function run(): void
    {
        $org = Organization::where('slug', 'elm-v2-demo')->firstOrFail();
        $site = Site::where('organization_id', $org->id)->where('nom', 'Siège V2 Demo')->firstOrFail();

        $personne = Personne::resoudreOuCreer($org->id, [
            'prenom' => 'Admin',
            'nom' => 'V2 Demo',
            'telephone' => self::TELEPHONE,
            'code_pays' => 'GN',
            'code_phone_pays' => '+224',
            'pays' => 'Guinée',
            'ville' => 'Conakry',
        ]);

        $user = User::updateOrCreate(
            ['personne_id' => $personne->id],
            ['organization_id' => $org->id, 'password' => self::PASSWORD, 'is_active' => true]
        );

        $user->authIdentities()->updateOrCreate(
            ['type' => UserAuthIdentity::TYPE_TELEPHONE],
            [
                'value' => self::TELEPHONE,
                'normalized_value' => Personne::normaliserTelephone(self::TELEPHONE),
                'verified_at' => now(),
                'is_primary' => true,
            ]
        );

        $user->syncRoles(['admin_entreprise']);
        app(MatriculeService::class)->assignForUser($user);

        $user->sites()->syncWithoutDetaching([
            $site->id => ['role' => 'employe', 'is_default' => true],
        ]);

        $this->command->newLine();
        $this->command->info('✓ Compte Eau La Maman V2 Demo créé :');
        $this->command->table(
            ['Nom', 'Téléphone', 'Rôle', 'Site', 'Mot de passe'],
            [['Admin V2 Demo', self::TELEPHONE, 'admin_entreprise', 'Siège V2 Demo (défaut)', self::PASSWORD]]
        );
    }
}
