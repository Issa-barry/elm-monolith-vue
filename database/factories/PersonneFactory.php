<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Personne;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Personne>
 */
class PersonneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'nom' => mb_strtoupper(fake()->lastName(), 'UTF-8'),
            'prenom' => fake()->firstName(),
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ];
    }

    /**
     * telephone_normalise dérive toujours de `telephone` — jamais fixé dans definition(), pour
     * rester cohérent même si un appelant surcharge `telephone` explicitement
     * (Personne::factory()->create(['telephone' => '+224622602693'])).
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Personne $personne) {
            $personne->telephone_normalise = $personne->telephone
                ? Personne::normaliserTelephone($personne->telephone)
                : null;
        });
    }
}
