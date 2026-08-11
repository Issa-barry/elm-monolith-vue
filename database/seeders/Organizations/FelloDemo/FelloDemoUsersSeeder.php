<?php

namespace Database\Seeders\Organizations\FelloDemo;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\MatriculeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Deux comptes staff de démo :
 *  - Admin Fello Demo (admin_entreprise) — accès aux deux boutiques, Madina
 *    par défaut.
 *  - Commercial Fello Demo (commerciale) — le rôle Spatie le plus proche
 *    d'un poste de vente en boutique existant dans le projet (aucun rôle
 *    "commercial"/"agent_vente"/"vendeur" n'existe ; 'commerciale' a déjà
 *    les permissions pdv.create/read/update et ventes.* — voir
 *    RolesAndPermissionsSeeder) — accès aux deux boutiques, Cosa par défaut.
 *
 * Numéros réservés à la démo (+224600000101/102), vérifiés sans collision
 * avec les numéros déjà utilisés par les seeders "elm".
 */
class FelloDemoUsersSeeder extends Seeder
{
    private const PASSWORD = 'FelloDemo@2025';

    private const STAFF = [
        [
            'prenom' => 'Admin',
            'nom' => 'Fello Demo',
            'telephone' => '+224600000101',
            'role' => 'admin_entreprise',
            'site_defaut' => 'Boutique Madina',
        ],
        [
            'prenom' => 'Commercial',
            'nom' => 'Fello Demo',
            'telephone' => '+224600000102',
            'role' => 'commerciale',
            'site_defaut' => 'Boutique Cosa',
        ],
    ];

    public function run(): void
    {
        $org = Organization::where('slug', 'fello-demo')->firstOrFail();
        $sites = Site::where('organization_id', $org->id)->get()->keyBy('nom');
        $matriculeService = app(MatriculeService::class);
        $recap = [];

        foreach (self::STAFF as $data) {
            $user = User::updateOrCreate(
                ['telephone' => $data['telephone']],
                [
                    'prenom' => $data['prenom'],
                    'nom' => $data['nom'],
                    'organization_id' => $org->id,
                    'code_pays' => 'GN',
                    'code_phone_pays' => '+224',
                    'pays' => 'Guinée',
                    'ville' => 'Conakry',
                    'password' => Hash::make(self::PASSWORD),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $user->syncRoles([$data['role']]);
            $matriculeService->assignForUser($user);

            // Rattaché aux deux boutiques d'un coup — pas besoin du correctif
            // "remet is_default=false sur les autres" utilisé ailleurs (cf.
            // UserSitesSeeder) puisque les deux valeurs sont fixées ici.
            $user->sites()->syncWithoutDetaching([
                $sites['Boutique Madina']->id => [
                    'role' => 'employe',
                    'is_default' => $data['site_defaut'] === 'Boutique Madina',
                ],
                $sites['Boutique Cosa']->id => [
                    'role' => 'employe',
                    'is_default' => $data['site_defaut'] === 'Boutique Cosa',
                ],
            ]);

            $recap[] = [
                trim("{$data['prenom']} {$data['nom']}"),
                $data['telephone'],
                $data['role'],
                'Madina, Cosa (défaut : '.$data['site_defaut'].')',
                self::PASSWORD,
            ];
        }

        $this->command->newLine();
        $this->command->info('✓ Comptes Fello Demo créés :');
        $this->command->table(['Nom', 'Téléphone', 'Rôle', 'Sites', 'Mot de passe'], $recap);
    }
}
