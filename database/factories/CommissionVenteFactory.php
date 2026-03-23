<?php

namespace Database\Factories;

use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommissionVenteFactory extends Factory
{
    public function definition(): array
    {
        $org             = Organization::factory()->create();
        $montantCommande = fake()->numberBetween(5000, 100000);
        $tauxLivreur     = 60.00;
        $tauxProp        = 40.00;
        $partLivreur     = round($montantCommande * $tauxLivreur / 100, 2);
        $partProp        = round($montantCommande * $tauxProp / 100, 2);

        return [
            'organization_id'              => $org->id,
            'commande_vente_id'            => CommandeVente::factory()->create(['organization_id' => $org->id])->id,
            'vehicule_id'                  => Vehicule::factory()->create(['organization_id' => $org->id])->id,
            'livreur_id'                   => null,
            'livreur_nom'                  => null,
            'taux_commission'              => $tauxLivreur,
            'taux_commission_proprietaire' => $tauxProp,
            'montant_commande'             => $montantCommande,
            'montant_commission'           => $partLivreur + $partProp,
            'montant_part_livreur'         => $partLivreur,
            'montant_part_proprietaire'    => $partProp,
            'montant_verse'                => 0,
            'montant_verse_livreur'        => 0,
            'montant_verse_proprietaire'   => 0,
            'statut'                       => StatutCommission::EN_ATTENTE,
        ];
    }

    public function avecLivreur(Livreur $livreur): static
    {
        return $this->state(fn () => [
            'livreur_id'  => $livreur->id,
            'livreur_nom' => trim($livreur->prenom . ' ' . $livreur->nom),
        ]);
    }
}
