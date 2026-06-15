<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class TypeVehiculeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'nom' => fake()->unique()->word(),
            'capacite_defaut' => fake()->numberBetween(50, 1000),
            'unite_capacite' => 'packs',
            'description' => null,
            'is_active' => true,
        ];
    }
}
