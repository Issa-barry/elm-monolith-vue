<?php

namespace Database\Seeders;

use App\Enums\TypeVehicule;
use App\Models\EquipeLivraison;
use App\Models\Organization;
use App\Models\Vehicule;
use Illuminate\Database\Seeder;

/**
 * Crée 3 véhicules et les associe à leurs équipes (equipes_livraison.vehicule_id).
 *
 * | Véhicule         | Type        | Équipe       | Catégorie | Taux prop |
 * |------------------|-------------|--------------|-----------|-----------|
 * | Nen Dow          | camion      | Nen Dow      | externe   | 60 %      |
 * | Kata Kata de Ali | tricycle    | Auto Dogomet | externe   | 60 %      |
 * | Baba Ousou       | camionnette | Baba Ousou   | externe   | 60 %      |
 */
class VehiculesSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $equipe = fn (string $nom) => EquipeLivraison::query()
            ->where('nom', $nom)
            ->where('organization_id', $org->id)
            ->firstOrFail();

        $eqNenDow = $equipe('Nen Dow');
        $eqAutoDogomet = $equipe('Auto Dogomet');
        $eqBabaOusou = $equipe('Baba Ousou');

        $vehicules = [
            [
                'nom_vehicule' => 'Nen Dow',
                'marque' => 'Mercedes',
                'modele' => 'Actros',
                'immatriculation' => 'RC-001-GN',
                'type_vehicule' => TypeVehicule::CAMION->value,
                'capacite_packs' => 500,
                'categorie' => 'externe',
                'proprietaire_id' => $eqNenDow->proprietaire_id,
                'pris_en_charge_par_usine' => false,
                'is_active' => true,
                'equipe' => $eqNenDow,
            ],
            [
                'nom_vehicule' => 'Kata Kata de Ali',
                'marque' => 'Bajaj',
                'modele' => 'RE',
                'immatriculation' => 'TC-001-GN',
                'type_vehicule' => TypeVehicule::TRICYCLE->value,
                'capacite_packs' => 80,
                'categorie' => 'externe',
                'proprietaire_id' => $eqAutoDogomet->proprietaire_id,
                'pris_en_charge_par_usine' => false,
                'is_active' => true,
                'equipe' => $eqAutoDogomet,
            ],
            [
                'nom_vehicule' => 'Baba Ousou',
                'marque' => 'Toyota',
                'modele' => 'HiAce',
                'immatriculation' => 'VN-001-GN',
                'type_vehicule' => TypeVehicule::CAMIONNETTE->value,
                'capacite_packs' => 150,
                'categorie' => 'externe',
                'proprietaire_id' => $eqBabaOusou->proprietaire_id,
                'pris_en_charge_par_usine' => false,
                'is_active' => true,
                'equipe' => $eqBabaOusou,
            ],
        ];

        foreach ($vehicules as $data) {
            $equipeModel = $data['equipe'];
            unset($data['equipe']);

            $vehicule = Vehicule::updateOrCreate(
                ['immatriculation' => $data['immatriculation'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id]
            );

            // Associer l'équipe au véhicule (nouvelle relation : equipes_livraison.vehicule_id)
            $equipeModel->update(['vehicule_id' => $vehicule->id]);
        }
    }
}
