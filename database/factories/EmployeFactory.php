<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'matricule' => strtoupper(fake()->bothify('EMP-####')),
            'nom' => strtoupper(fake()->lastName()),
            'prenom' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'telephone' => '+224'.fake()->numerify('#########'),
            'type_employe' => 'interne',
            'statut' => 'actif',
            'site_id' => null,
        ];
    }
}
