<?php

namespace Database\Factories;

use App\Enums\CategorieVehicule;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\TypeVehicule;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehiculeFactory extends Factory
{
    public function definition(): array
    {
        $org = Organization::factory()->create();
        $typeVehicule = TypeVehicule::factory()->create(['organization_id' => $org->id]);

        return [
            'organization_id' => $org->id,
            'nom_vehicule' => fake()->word().' '.fake()->numberBetween(1, 99),
            'immatriculation' => strtoupper(fake()->bothify('??-###-??')),
            'type_vehicule_id' => $typeVehicule->id,
            'capacite_packs' => $typeVehicule->capacite_defaut,
            // Propriétaire par défaut : un tiers réel (jamais l'interne par défaut de
            // l'organisation) — categorie PARTENAIRE cohérente avec ce choix, cf.
            // CategorieVehicule::coherentAvecProprietaireTiers(). Un test qui veut un véhicule
            // INTERNE doit surcharger explicitement les deux (categorie ET proprietaire_id).
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $org->id])->id,
            'categorie' => CategorieVehicule::PARTENAIRE,
            'livraison_vente' => true,
            'livraison_logistique' => false,
            'is_active' => true,
        ];
    }
}
