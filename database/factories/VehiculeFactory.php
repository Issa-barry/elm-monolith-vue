<?php

namespace Database\Factories;

use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehiculeFactory extends Factory
{
    public function definition(): array
    {
        $org = Organization::factory()->create();

        return [
            'organization_id' => $org->id,
            'nom_vehicule' => fake()->word().' '.fake()->numberBetween(1, 99),
            'immatriculation' => strtoupper(fake()->bothify('??-###-??')),
            'type_vehicule' => 'camion',
            'capacite_packs' => 200,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $org->id])->id,
            'livreur_principal_id' => null,
            'taux_commission_livreur' => 60.00,
            'taux_commission_proprietaire' => 40.00,
            'commission_active' => true,
            'is_active' => true,
        ];
    }

    public function avecLivreur(Livreur $livreur): static
    {
        return $this->state(fn () => ['livreur_principal_id' => $livreur->id]);
    }

    public function sansCommission(): static
    {
        return $this->state(fn () => [
            'taux_commission_livreur' => 0,
            'taux_commission_proprietaire' => 0,
        ]);
    }
}
