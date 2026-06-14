<?php

namespace Database\Factories;

use App\Enums\StatutDepense;
use App\Models\DepenseType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DepenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'depense_type_id' => DepenseType::factory(),
            'beneficiaire_type' => null,
            'beneficiaire_id' => null,
            'site_id' => null,
            'montant' => fake()->numberBetween(5000, 500000),
            'date_depense' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'commentaire' => null,
            'statut' => StatutDepense::BROUILLON->value,
            'validateur_id' => null,
            'date_validation' => null,
            'motif_rejet' => null,
            'justificatif_path' => null,
        ];
    }

    public function brouillon(): static
    {
        return $this->state(['statut' => StatutDepense::BROUILLON->value]);
    }

    public function soumis(): static
    {
        return $this->state(['statut' => StatutDepense::SOUMIS->value]);
    }

    public function valide(): static
    {
        return $this->state(['statut' => StatutDepense::VALIDE->value]);
    }

    public function rejete(string $motif = 'Non conforme'): static
    {
        return $this->state([
            'statut' => StatutDepense::REJETE->value,
            'motif_rejet' => $motif,
        ]);
    }

    public function annule(): static
    {
        return $this->state([
            'statut' => StatutDepense::ANNULE->value,
            'motif_rejet' => null,
        ]);
    }
}
