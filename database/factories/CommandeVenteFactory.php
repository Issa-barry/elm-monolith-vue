<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommandeVenteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'vehicule_id' => null,
            'site_id' => null,
            'client_id' => null,
            'reference' => 'CMD-'.now()->format('dmY').'-'.str_pad((string) fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'total_commande' => fake()->numberBetween(5000, 100000),
            'statut' => 'brouillon',
        ];
    }
}
