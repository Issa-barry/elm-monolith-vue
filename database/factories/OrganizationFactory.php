<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'name'      => $name,
            'slug'      => Str::slug($name) . '-' . $this->faker->unique()->numberBetween(1000, 9999),
            'siret'     => null,
            'is_active' => true,
        ];
    }
}
