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
                // Propriétaire par défaut des véhicules "interne" (propriété de
                // l'organisation) — rattaché ci-dessous à Organization::proprietaire_interne_id.
                // Distinct du compte User admin_entreprise "Moussa SIDIBÉ"
                // (téléphone différent) : ici c'est une fiche Proprietaire, pas
                // un utilisateur du back-office.
                'nom' => 'SIDIBE',
                'prenom' => 'Moussa',
                'email' => 'moussa.sidibe@elm.gn',
                'telephone' => '+224622602693',
                'adresse' => 'Matoto',
                'ville' => 'Conakry',
                'is_active' => true,
            ],
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

        $proprietaireInterne = null;
        foreach ($sansCompte as $data) {
            $proprietaire = Proprietaire::firstOrCreate(
                ['telephone' => $data['telephone'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id]
            );

            if ($data['telephone'] === '+224622602693') {
                $proprietaireInterne = $proprietaire;
            }
        }

        // Propriétaire interne par défaut de l'organisation "elm" — véhicules "interne" et
        // commissions propriétaire associées (cf. Organization::proprietaireInterne(),
        // Proprietaire::interneParDefautId()). Pour toute autre organisation, ce rattachement
        // se fait à l'installation (InstallationService::install()), jamais via ce seeder de
        // démonstration.
        if ($proprietaireInterne && ! $org->proprietaire_interne_id) {
            $org->forceFill(['proprietaire_interne_id' => $proprietaireInterne->id])->save();
        }

        // ── Propriétaires avec compte (accès portail client) ──────────────────
        $avecCompte = [
            [
                'nom' => 'DIALLO',
                'prenom' => 'Amadou',
                'email' => 'amadou.diallo@elm.gn',
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
                    'email' => $data['email'] ?? null,
                    'email_verified_at' => isset($data['email']) ? now() : null,
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
