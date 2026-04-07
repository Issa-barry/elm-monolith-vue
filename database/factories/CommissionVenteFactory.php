<?php

namespace Database\Factories;

use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommissionVenteFactory extends Factory
{
    public function definition(): array
    {
        $org = Organization::factory()->create();
        $montantCommande = fake()->numberBetween(5000, 100000);
        $montantCommissionTotale = round($montantCommande * 0.10, 2);

        return [
            'organization_id' => $org->id,
            'commande_vente_id' => CommandeVente::factory()->create(['organization_id' => $org->id])->id,
            'vehicule_id' => Vehicule::factory()->create(['organization_id' => $org->id])->id,
            'montant_commande' => $montantCommande,
            'montant_commission_totale' => $montantCommissionTotale,
            'montant_verse' => 0,
            'statut' => StatutCommission::EN_ATTENTE,
        ];
    }
}
