<?php

namespace Database\Factories;

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
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $org->id])->id,
            'categorie' => 'externe',
            'pris_en_charge_par_usine' => false,
            'is_active' => true,
        ];
    }
}
