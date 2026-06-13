<?php

namespace Database\Factories;

use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use Illuminate\Database\Eloquent\Factories\Factory;

class FactureVenteFactory extends Factory
{
    public function definition(): array
    {
        $montant = fake()->numberBetween(5000, 100000);
        $commande = CommandeVente::factory()->create();

        return [
            'organization_id' => $commande->organization_id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => StatutFactureVente::IMPAYEE,
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
