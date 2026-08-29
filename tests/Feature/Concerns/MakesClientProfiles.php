<?php

namespace Tests\Feature\Concerns;

use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Helpers pour construire des utilisateurs "espace client" (proprietaire/livreur)
 * complets — User + rôle Spatie + profil métier lié par user_id — utilisés par les
 * tests d'authentification et d'isolation des endpoints Client\*.
 */
trait MakesClientProfiles
{
    protected function ensureClientRoles(): void
    {
        foreach (['client', 'proprietaire', 'livreur'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    protected function makeProprietaireUser(Organization $org, array $userOverrides = [], array $proprietaireOverrides = []): User
    {
        $this->ensureClientRoles();
        $telephone = $userOverrides['telephone'] ?? ('+224'.fake()->unique()->numerify('#########'));

        $user = User::factory()->create(array_merge([
            'organization_id' => $org->id,
            'telephone' => $telephone,
            'password' => 'Password@123',
        ], $userOverrides));
        $user->assignRole('proprietaire');

        Proprietaire::factory()->create(array_merge([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'telephone' => $telephone,
        ], $proprietaireOverrides));

        return $user->fresh();
    }

    protected function makeLivreurUser(Organization $org, array $userOverrides = [], array $livreurOverrides = []): User
    {
        $this->ensureClientRoles();
        $telephone = $userOverrides['telephone'] ?? ('+224'.fake()->unique()->numerify('#########'));

        $user = User::factory()->create(array_merge([
            'organization_id' => $org->id,
            'telephone' => $telephone,
            'password' => 'Password@123',
        ], $userOverrides));
        $user->assignRole('livreur');

        Livreur::factory()->create(array_merge([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'telephone' => $telephone,
        ], $livreurOverrides));

        return $user->fresh();
    }
}
