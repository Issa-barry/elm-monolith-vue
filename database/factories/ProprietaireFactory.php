<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Personne;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

class ProprietaireFactory extends Factory
{
    private const IDENTITY_KEYS = ['nom', 'prenom', 'surnom', 'email', 'telephone', 'pays', 'code_pays', 'code_phone_pays', 'ville', 'adresse'];

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'is_active' => true,
        ];
    }

    /**
     * Compatibilité : `Proprietaire::factory()->create(['nom' => ..., 'telephone' => ...])`
     * reste possible malgré le déplacement de l'identité vers Personne — routé vers une
     * Personne dédiée de la même organisation plutôt que silencieusement ignoré.
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
                'telephone' => '+2246'.fake()->numerify('########'),
                'code_phone_pays' => '+224',
                'code_pays' => 'GN',
                'pays' => 'Guinée',
                'ville' => 'Conakry',
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
