<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CommandeVenteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'vehicule_id'     => null,
            'site_id'         => null,
            'client_id'       => null,
            'reference'       => 'VNT-TEST-' . strtoupper(Str::random(8)),
            'total_commande'  => fake()->numberBetween(5000, 100000),
            'statut'          => 'livree',
        ];
    }
}
