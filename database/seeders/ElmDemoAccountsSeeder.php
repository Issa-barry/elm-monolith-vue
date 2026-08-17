<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Personne;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Services\MatriculeService;
use Illuminate\Database\Seeder;

/**
 * Comptes staff de démonstration pour l'organisation "elm" (mot de passe connu Staff@2025) —
 * réservé au dev local / CI (via DatabaseSeeder) ou à un environnement de démo dédié, jamais à
 * prod/preprod. Extrait de RolesAndPermissionsSeeder suite à l'incident du 2026-08-12 : un seeder
 * de production lancé avant configuration complète du .env sur l'hébergement preprod avait créé
 * ces comptes via l'ancien guard `app()->environment('production')` — fragile car dépendant de
 * l'ordre exact des étapes de déploiement. La sécurité vient maintenant du fait que ce seeder
 * n'est JAMAIS appelé par le pipeline de déploiement (qui ne seede que RolesAndPermissionsSeeder),
 * pas d'une valeur d'APP_ENV.
 *
 * Requiert que RolesAndPermissionsSeeder::seedRolesEtPermissions() ait déjà tourné (rôles) et que
 * l'organisation "elm" existe déjà (cf. ElmDemoOrganizationSeeder, appelé juste avant dans
 * DatabaseSeeder).
 *
 * Lancement isolé : php artisan db:seed --class=ElmDemoAccountsSeeder
 */
class ElmDemoAccountsSeeder extends Seeder
{
    private const PASSWORD = 'Staff@2025';

    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $pays = [
            'FR' => ['France', '+33'],
            'GN' => ['Guinée', '+224'],
        ];

        $staff = [
            [
                'prenom' => 'Issa',
                'nom' => 'BARRY',
                'telephone' => '+33758855039',
                'code_pays' => 'FR',
                'role' => 'super_admin',
            ],
            [
                'prenom' => 'Abdoulaye',
                'nom' => 'DIALLO',
                'telephone' => '+33769442565',
                'code_pays' => 'FR',
                'role' => 'admin_entreprise',
            ],
            [
                'prenom' => 'Moussa',
                'nom' => 'SIDIBÉ',
                'telephone' => '+224656555520',
                'code_pays' => 'GN',
                'role' => 'admin_entreprise',
            ],
            [
                'prenom' => 'Thierno Oumar',
                'nom' => 'DIALLO',
                'telephone' => '+224622176056',
                'code_pays' => 'GN',
                'role' => 'manager',
            ],
            [
                'prenom' => 'Aminata',
                'nom' => 'DIALLO',
                'telephone' => null,
                'code_pays' => null,
                'role' => 'comptable',
            ],
            [
                'prenom' => 'Alpha Oumar',
                'nom' => 'CAMARA',
                'telephone' => null,
                'code_pays' => null,
                'role' => 'commerciale',
            ],
            [
                'prenom' => 'Elhadj Oumar',
                'nom' => 'TALL',
                'telephone' => '+33605751596',
                'code_pays' => 'FR',
                'role' => 'super_admin',
            ],
        ];

        foreach ($staff as $data) {
            $codePays = $data['code_pays'];
            $paysNom = $codePays ? $pays[$codePays][0] : null;
            $codePhone = $codePays ? $pays[$codePays][1] : null;

            // Sans téléphone connu ("à définir"), la Personne se résout par nom+prénom dans
            // l'organisation plutôt que par téléphone (rien à dédupliquer sinon) — reste
            // idempotent au re-seed.
            $personne = $data['telephone']
                ? Personne::resoudreOuCreer($org->id, [
                    'prenom' => $data['prenom'],
                    'nom' => $data['nom'],
                    'telephone' => $data['telephone'],
                    'code_pays' => $codePays,
                    'pays' => $paysNom,
                    'code_phone_pays' => $codePhone,
                ])
                : Personne::firstOrCreate(
                    ['organization_id' => $org->id, 'nom' => $data['nom'], 'prenom' => $data['prenom']],
                    []
                );

            // updateOrCreate garantit que le mot de passe est toujours réinitialisé
            // lors d'un re-seed, même si le compte existe déjà.
            $user = User::updateOrCreate(
                ['personne_id' => $personne->id],
                ['password' => self::PASSWORD, 'organization_id' => $org->id]
            );

            if ($data['telephone']) {
                $user->authIdentities()->updateOrCreate(
                    ['type' => UserAuthIdentity::TYPE_TELEPHONE],
                    [
                        'value' => $data['telephone'],
                        'normalized_value' => Personne::normaliserTelephone($data['telephone']),
                        'verified_at' => now(),
                        'is_primary' => true,
                    ]
                );
            }

            $user->syncRoles([$data['role']]);
            app(MatriculeService::class)->assignForUser($user);
        }

        $this->command->newLine();
        $this->command->info('✓ Comptes de démo "elm" créés avec succès.');
        $this->command->newLine();
        $this->command->table(
            ['Prénom Nom', 'Téléphone', 'Rôle', 'Mot de passe'],
            [
                ['Issa BARRY', '+33758855039', 'super_admin', self::PASSWORD],
                ['Abdoulaye DIALLO', '+33769442565', 'admin_entreprise', self::PASSWORD],
                ['Moussa SIDIBÉ', '+224656555520', 'admin_entreprise', self::PASSWORD],
                ['Thierno Oumar DIALLO', '+224622176056', 'manager', self::PASSWORD],
                ['Aminata DIALLO', '— (à définir)', 'comptable', self::PASSWORD],
                ['Alpha Oumar CAMARA', '— (à définir)', 'commerciale', self::PASSWORD],
                ['Elhadj Oumar TALL', '+33605751596', 'super_admin', self::PASSWORD],
            ]
        );
    }
}
