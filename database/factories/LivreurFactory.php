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
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'telephone' => '+224'.fake()->numerify('#########'),
            'ville' => fake()->city(),
            'pays' => 'Guinée',
            'code_pays' => 'GN',
            'code_phone_pays' => '+224',
            'is_active' => true,
        ];
    }
}
