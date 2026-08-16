<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Personne;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

class EmployeFactory extends Factory
{
    private const IDENTITY_KEYS = ['nom', 'prenom', 'email', 'telephone'];

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'matricule' => strtoupper(fake()->bothify('EMP-####')),
            'type_employe' => 'interne',
            'statut' => 'actif',
            'site_id' => null,
        ];
    }

    /**
     * Compatibilité : `Employe::factory()->create(['nom' => ..., 'telephone' => ...])` reste
     * possible malgré le déplacement de l'identité civile vers Personne.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (is_array($attributes) && ! isset($attributes['personne_id'])) {
            $orgId = $attributes['organization_id'] ?? Organization::factory()->create()->id;
            $attributes['organization_id'] = $orgId;

            $overrides = array_intersect_key($attributes, array_flip(self::IDENTITY_KEYS));
            $defaults = [
                'nom' => strtoupper(fake()->lastName()),
                'prenom' => fake()->firstName(),
                'email' => fake()->unique()->safeEmail(),
                'telephone' => '+224'.fake()->numerify('#########'),
            ];

            $attributes['personne_id'] = Personne::factory()->create([
                'organization_id' => $orgId,
                ...$defaults,
                ...$overrides,
            ])->id;

            foreach (self::IDENTITY_KEYS as $key) {
                unset($attributes[$key]);
            }
        }

        return parent::create($attributes, $parent);
    }
}
