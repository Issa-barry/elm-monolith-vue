<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ProprietairesSeeder extends Seeder
{
    private const PASSWORD = 'Staff@2025';

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $org = Organization::where('slug', 'elm')->firstOrFail();
        $proprietaireRole = Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']);

        // ── Propriétaires sans compte (gestion interne uniquement) ────────────
        $sansCompte = [
            [
                'nom' => 'BARRY',
                'prenom' => 'Mamadou',
                'email' => 'mamadou.barry@elm.gn',
                'telephone' => '+224621000001',
                'adresse' => 'Kaloum',
                'ville' => 'Conakry',
                'is_active' => true,
            ],
            [
                'nom' => 'DIALLO',
                'prenom' => 'Fatoumata',
                'email' => 'fatoumata.diallo@elm.gn',
                'telephone' => '+224621000002',
                'adresse' => 'Ratoma',
                'ville' => 'Conakry',
                'is_active' => true,
            ],
            [
                'nom' => 'TOUNKARA',
                'prenom' => 'Issa',
                'email' => 'issa.tounkara@elm.gn',
                'telephone' => '+224621000003',
                'adresse' => 'Matoto',
                'ville' => 'Conakry',
                'is_active' => true,
            ],
            [
                'nom' => 'CONDÉ',
                'prenom' => 'Saran',
                'email' => 'saran.conde@elm.gn',
                'telephone' => '+224621000004',
                'adresse' => 'Dixinn',
                'ville' => 'Conakry',
                'is_active' => true,
            ],
        ];

        foreach ($sansCompte as $data) {
            Proprietaire::firstOrCreate(
                ['telephone' => $data['telephone'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id]
            );
        }

        // ── Propriétaires avec compte (accès portail client) ──────────────────
        $avecCompte = [
            [
                'nom' => 'DIALLO',
                'prenom' => 'Amadou',
                'telephone' => '+33754158797',
                'code_pays' => 'FR',
                'is_active' => true,
            ],
        ];

        foreach ($avecCompte as $data) {
            $user = User::updateOrCreate(
                ['telephone' => $data['telephone']],
                [
                    'prenom' => $data['prenom'],
                    'nom' => $data['nom'],
                    'email' => null,
                    'password' => Hash::make(self::PASSWORD),
                ]
            );
            $user->roles()->syncWithoutDetaching([$proprietaireRole->id]);

            Proprietaire::updateOrCreate(
                ['telephone' => $data['telephone'], 'organization_id' => $org->id],
                [
                    'nom' => $data['nom'],
                    'prenom' => $data['prenom'],
                    'telephone' => $data['telephone'],
                    'code_pays' => $data['code_pays'] ?? null,
                    'is_active' => $data['is_active'],
                    'user_id' => $user->id,
                    'organization_id' => $org->id,
                ]
            );
        }

        $this->command->newLine();
        $this->command->info('✓ Propriétaires créés avec succès.');
        $this->command->newLine();
        $this->command->table(
            ['Prénom Nom', 'Téléphone', 'Compte portail', 'Mot de passe'],
            array_map(fn ($c) => [
                $c['prenom'].' '.$c['nom'],
                $c['telephone'],
                'Oui',
                self::PASSWORD,
            ], $avecCompte)
        );
    }
}
