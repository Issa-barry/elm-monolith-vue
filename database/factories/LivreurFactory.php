<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class LivreurFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'nom'       => fake()->lastName(),
            'prenom'    => fake()->firstName(),
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
            'is_active' => true,
        ];
    }
}
