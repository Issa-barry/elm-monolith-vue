<?php

namespace Database\Factories;

use App\Enums\CategorieDepense;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class DepenseTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => fake()->unique()->slug(2),
            'libelle' => fake()->words(2, true),
            'description' => null,
            'categorie' => fake()->randomElement(CategorieDepense::values()),
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'type_paie' => null,
            'is_active' => true,
        ];
    }

    public function interne(): static
    {
        return $this->state(['categorie' => CategorieDepense::INTERNE->value]);
    }

    public function employe(): static
    {
        return $this->state(['categorie' => CategorieDepense::EMPLOYE->value]);
    }

    public function livreur(): static
    {
        return $this->state(['categorie' => CategorieDepense::LIVREUR->value]);
    }

    public function proprietaire(): static
    {
        return $this->state(['categorie' => CategorieDepense::PROPRIETAIRE->value]);
    }

    public function vehicule(): static
    {
        return $this->state(['categorie' => CategorieDepense::VEHICULE->value]);
    }
}
