<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Services\MatriculeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crée l'unique compte super_admin réel de mise en production.
 *
 * Ne seed aucun autre utilisateur : les comptes staff (admin_entreprise,
 * manager, comptable, commerciale...) sont ajoutés ensuite via le flux
 * d'invitation de l'application, pas par seeder.
 *
 * Identifiants lus depuis l'environnement (SUPER_ADMIN_*) ou demandés de
 * façon interactive si absents — jamais de valeur codée en dur ici.
 */
class SuperAdminSeeder extends Seeder
{
    private const PAYS = [
        'GN' => ['Guinée', '+224'],
        'FR' => ['France', '+33'],
    ];

    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $prenom = env('SUPER_ADMIN_PRENOM') ?: $this->command->ask('Prénom du super admin');
        $nom = env('SUPER_ADMIN_NOM') ?: $this->command->ask('Nom du super admin');
        $telephone = env('SUPER_ADMIN_TELEPHONE') ?: $this->command->ask('Téléphone (format E.164, ex: +224620000000)');
        $codePays = env('SUPER_ADMIN_CODE_PAYS') ?: $this->command->ask('Code pays', 'GN');

        [$paysNom, $codePhonePays] = self::PAYS[strtoupper($codePays)] ?? [null, null];

        $password = env('SUPER_ADMIN_PASSWORD');
        $generated = false;
        if (! $password) {
            $password = Str::password(16);
            $generated = true;
        }

        $user = User::updateOrCreate(
            ['telephone' => $telephone],
            [
                'prenom' => $prenom,
                'nom' => $nom,
                'telephone' => $telephone,
                'code_pays' => strtoupper($codePays),
                'pays' => $paysNom,
                'code_phone_pays' => $codePhonePays,
                'email' => null,
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'organization_id' => $org->id,
            ]
        );

        $user->syncRoles(['super_admin']);
        app(MatriculeService::class)->assignForUser($user);

        $this->command->newLine();
        $this->command->info("✓ Super admin créé : {$prenom} {$nom} ({$telephone})");

        if ($generated) {
            $this->command->warn("Mot de passe généré : {$password}");
            $this->command->warn('Note-le maintenant — il ne sera plus jamais affiché.');
        }
    }
}
