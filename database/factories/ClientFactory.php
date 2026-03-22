<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id'         => null,
            'nom'             => $this->faker->lastName(),
            'prenom'          => $this->faker->firstName(),
            'email'           => $this->faker->optional()->safeEmail(),
            'telephone'       => $this->faker->optional()->phoneNumber(),
            'adresse'         => $this->faker->optional()->address(),
            'is_active'       => true,
        ];
    }
}
