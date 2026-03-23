<?php

namespace Database\Factories;

use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class FactureVenteFactory extends Factory
{
    public function definition(): array
    {
        $montant = fake()->numberBetween(5000, 100000);

        return [
            'organization_id'  => Organization::factory(),
            'commande_vente_id' => CommandeVente::factory(),
            'montant_brut'     => $montant,
            'montant_net'      => $montant,
            'statut_facture'   => StatutFactureVente::IMPAYEE,
        ];
    }

    public function impayee(): static
    {
        return $this->state(fn () => ['statut_facture' => StatutFactureVente::IMPAYEE]);
    }

    public function payee(): static
    {
        return $this->state(fn () => ['statut_facture' => StatutFactureVente::PAYEE]);
    }
}
