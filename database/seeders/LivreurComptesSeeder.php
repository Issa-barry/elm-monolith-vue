<?php

namespace Database\Seeders;

use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Cree des comptes utilisateur pour des livreurs.
 *
 * Cas couverts:
 * - Livreur actif: compte lie a un livreur pre-cree
 * - Livreur pending: simulation auto-inscription, is_active = false sur le livreur
 * - Livreur + proprietaire: compte double role
 */
class LivreurComptesSeeder extends Seeder
{
    private const PASSWORD = 'Staff@2025';

    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        Role::firstOrCreate(['name' => 'livreur', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']);

        // 1) Livreurs actifs
        $actifs = [
            [
                'telephone' => '+224622000001',
                'prenom' => 'Ibrahima',
                'nom' => 'CAMARA',
                'email' => 'ibrahima.camara@elm.gn',
            ],
            [
                'telephone' => '+224622000004',
                'prenom' => 'Mamadou',
                'nom' => 'SOUMAH',
                'email' => 'mamadou.soumah@elm.gn',
            ],
        ];

        foreach ($actifs as $data) {
            $livreur = Livreur::firstOrCreate(
                ['telephone' => $data['telephone'], 'organization_id' => $org->id],
                [
                    'prenom' => $data['prenom'],
                    'nom' => $data['nom'],
                    'telephone' => $data['telephone'],
                    'organization_id' => $org->id,
                    'is_active' => true,
                ]
            );

            $user = $this->upsertUser($data, $org->id);
            $user->syncRoles(['livreur']);

            if ((string) $livreur->user_id !== (string) $user->id) {
                $livreur->update(['user_id' => $user->id]);
            }
        }

        // 2) Livreur pending
        $pending = [
            [
                'telephone' => '+224628000099',
                'prenom' => 'Oumar',
                'nom' => 'BALDE',
                'email' => 'oumar.balde@elm.gn',
            ],
        ];

        foreach ($pending as $data) {
            $user = $this->upsertUser($data, $org->id);
            $user->syncRoles(['livreur']);

            $livreur = Livreur::updateOrCreate(
                ['telephone' => $data['telephone'], 'organization_id' => $org->id],
                [
                    'user_id' => $user->id,
                    'prenom' => $data['prenom'],
                    'nom' => $data['nom'],
                    'telephone' => $data['telephone'],
                    'organization_id' => $org->id,
                    'is_active' => false,
                ]
            );

            if ((string) $livreur->user_id !== (string) $user->id) {
                $livreur->update(['user_id' => $user->id]);
            }
        }

        // 3) Livreur + proprietaire (double role)
        $dual = [
            'telephone' => '+224622000007',
            'prenom' => 'Alpha',
            'nom' => 'BARRY',
            'email' => 'alpha.barry@elm.gn',
        ];

        $dualLivreur = Livreur::firstOrCreate(
            ['telephone' => $dual['telephone'], 'organization_id' => $org->id],
            [
                'prenom' => $dual['prenom'],
                'nom' => $dual['nom'],
                'telephone' => $dual['telephone'],
                'organization_id' => $org->id,
                'is_active' => true,
            ]
        );

        $dualUser = $this->upsertUser($dual, $org->id);
        $dualUser->syncRoles(['livreur', 'proprietaire']);

        if ((string) $dualLivreur->user_id !== (string) $dualUser->id) {
            $dualLivreur->update(['user_id' => $dualUser->id]);
        }

        Proprietaire::updateOrCreate(
            ['telephone' => $dual['telephone'], 'organization_id' => $org->id],
            [
                'nom' => $dual['nom'],
                'prenom' => $dual['prenom'],
                'telephone' => $dual['telephone'],
                'is_active' => true,
                'user_id' => $dualUser->id,
                'organization_id' => $org->id,
            ]
        );

        $this->command->newLine();
        $this->command->info('Comptes livreurs crees.');
        $this->command->newLine();
        $this->command->table(
            ['Prenom Nom', 'Telephone', 'Roles', 'Mot de passe'],
            [
                ['Ibrahima CAMARA', '+224622000001', 'livreur (actif)', self::PASSWORD],
                ['Mamadou SOUMAH', '+224622000004', 'livreur (actif)', self::PASSWORD],
                ['Oumar BALDE', '+224628000099', 'livreur (pending)', self::PASSWORD],
                ['Alpha BARRY', '+224622000007', 'livreur + proprietaire', self::PASSWORD],
            ]
        );
        $this->command->newLine();
        $this->command->line('-> Actif: acces complet a /client/dashboard');
        $this->command->line("-> En attente: redirige vers /client/pending jusqu'a validation admin");
        $this->command->line('-> Double role: QR code pointe vers la fiche proprietaire');
        $this->command->newLine();
    }

    /**
     * Assure un compte idempotent et remet le mot de passe du seed a chaque execution.
     */
    private function upsertUser(array $data, string $organizationId): User
    {
        return User::updateOrCreate(
            ['telephone' => $data['telephone']],
            [
                'prenom' => $data['prenom'],
                'nom' => $data['nom'],
                'email' => $data['email'] ?? null,
                'telephone' => $data['telephone'],
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => isset($data['email']) ? now() : null,
                'organization_id' => $organizationId,
            ]
        );
    }
}
