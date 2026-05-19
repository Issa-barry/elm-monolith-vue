<?php

namespace Database\Seeders;

use App\Models\Livreur;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Crée des comptes utilisateur pour des livreurs.
 *
 * Cas couverts :
 *  – Livreur actif  : compte lié à un livreur pré-existant (créé par admin), is_active = true
 *  – Livreur pending: simule une auto-inscription en attente de validation, is_active = false
 */
class LivreurComptesSeeder extends Seeder
{
    private const PASSWORD = 'Staff@2025';

    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        Role::firstOrCreate(['name' => 'livreur', 'guard_name' => 'web']);

        // ── 1. Livreurs actifs (pré-créés par admin, compte lié) ─────────────

        $actifs = [
            [
                'telephone' => '+224622000001', // Ibrahima CAMARA (Nen Dow principal)
                'prenom'    => 'Ibrahima',
                'nom'       => 'CAMARA',
            ],
            [
                'telephone' => '+224622000004', // Mamadou SOUMAH (Kaloum Express principal)
                'prenom'    => 'Mamadou',
                'nom'       => 'SOUMAH',
            ],
        ];

        foreach ($actifs as $data) {
            $livreur = Livreur::where('telephone', $data['telephone'])
                ->where('organization_id', $org->id)
                ->first();

            if (! $livreur) {
                continue;
            }

            $user = User::firstOrCreate(
                ['telephone' => $data['telephone']],
                [
                    'prenom'          => $data['prenom'],
                    'nom'             => $data['nom'],
                    'telephone'       => $data['telephone'],
                    'password'        => Hash::make(self::PASSWORD),
                    'organization_id' => $org->id,
                ]
            );

            $user->syncRoles(['livreur']);

            if (! $livreur->user_id) {
                $livreur->update(['user_id' => $user->id]);
            }
        }

        // ── 2. Livreur en attente (auto-inscription, is_active = false) ──────

        $pending = [
            [
                'telephone' => '+224628000099',
                'prenom'    => 'Oumar',
                'nom'       => 'BALDE',
            ],
        ];

        foreach ($pending as $data) {
            $user = User::firstOrCreate(
                ['telephone' => $data['telephone']],
                [
                    'prenom'          => $data['prenom'],
                    'nom'             => $data['nom'],
                    'telephone'       => $data['telephone'],
                    'password'        => Hash::make(self::PASSWORD),
                    'organization_id' => $org->id,
                ]
            );

            $user->syncRoles(['livreur']);

            Livreur::firstOrCreate(
                ['telephone' => $data['telephone'], 'organization_id' => $org->id],
                [
                    'user_id'         => $user->id,
                    'prenom'          => $data['prenom'],
                    'nom'             => $data['nom'],
                    'telephone'       => $data['telephone'],
                    'organization_id' => $org->id,
                    'is_active'       => false,
                ]
            );
        }

        // ── Résumé ────────────────────────────────────────────────────────────
        $this->command->newLine();
        $this->command->info('✓ Comptes livreurs créés.');
        $this->command->newLine();
        $this->command->table(
            ['Prénom Nom', 'Téléphone', 'Statut', 'Mot de passe'],
            [
                ['Ibrahima CAMARA', '+224622000001', 'Actif',   self::PASSWORD],
                ['Mamadou SOUMAH',  '+224622000004', 'Actif',   self::PASSWORD],
                ['Oumar BALDE',     '+224628000099', 'En attente (pending)', self::PASSWORD],
            ]
        );
        $this->command->newLine();
        $this->command->line('  → Actif    : accès complet à /client/dashboard');
        $this->command->line('  → En attente : redirigé vers /client/pending jusqu\'à validation admin');
        $this->command->newLine();
    }
}
