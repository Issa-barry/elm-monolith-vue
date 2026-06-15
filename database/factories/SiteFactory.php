<?php

namespace Database\Factories;

use App\Enums\SiteType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'nom' => fake()->unique()->city(),
            'type' => SiteType::AGENCE->value,
        ];
    }
}
