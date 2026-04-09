<?php

namespace Database\Seeders;

use App\Enums\TypeVehicule;
use App\Models\EquipeLivraison;
use App\Models\Organization;
use App\Models\Vehicule;
use Illuminate\Database\Seeder;

/**
 * Cree 3 vehicules.
 *
 * proprietaire_id et taux_commission_proprietaire sont derives de l'equipe assignee.
 *
 * | Vehicule         | Type        | Equipe       | Taux prop | Somme equipe |
 * |------------------|-------------|--------------|-----------|--------------|
 * | Nen Dow          | camion      | Nen Dow      | 60 %      | 40 %         |
 * | Kata Kata de Ali | tricycle    | Auto Dogomet | 60 %      | 40 %         |
 * | Baba Ousou       | camionnette | Baba Ousou   | 60 %      | 40 %         |
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

        $eqNenDow     = $equipe('Nen Dow');
        $eqAutoDogomet = $equipe('Auto Dogomet');
        $eqBabaOusou  = $equipe('Baba Ousou');

        $vehicules = [
            [
                'nom_vehicule'               => 'Nen Dow',
                'marque'                     => 'Mercedes',
                'modele'                     => 'Actros',
                'immatriculation'            => 'RC-001-GN',
                'type_vehicule'              => TypeVehicule::CAMION->value,
                'capacite_packs'             => 500,
                'equipe_livraison_id'        => $eqNenDow->id,
                'proprietaire_id'            => $eqNenDow->proprietaire_id,
                'taux_commission_proprietaire' => 100 - $eqNenDow->sommeTaux(),
                'pris_en_charge_par_usine'   => false,
                'is_active'                  => true,
            ],
            [
                'nom_vehicule'               => 'Kata Kata de Ali',
                'marque'                     => 'Bajaj',
                'modele'                     => 'RE',
                'immatriculation'            => 'TC-001-GN',
                'type_vehicule'              => TypeVehicule::TRICYCLE->value,
                'capacite_packs'             => 80,
                'equipe_livraison_id'        => $eqAutoDogomet->id,
                'proprietaire_id'            => $eqAutoDogomet->proprietaire_id,
                'taux_commission_proprietaire' => 100 - $eqAutoDogomet->sommeTaux(),
                'pris_en_charge_par_usine'   => false,
                'is_active'                  => true,
            ],
            [
                'nom_vehicule'               => 'Baba Ousou',
                'marque'                     => 'Toyota',
                'modele'                     => 'HiAce',
                'immatriculation'            => 'VN-001-GN',
                'type_vehicule'              => TypeVehicule::CAMIONNETTE->value,
                'capacite_packs'             => 150,
                'equipe_livraison_id'        => $eqBabaOusou->id,
                'proprietaire_id'            => $eqBabaOusou->proprietaire_id,
                'taux_commission_proprietaire' => 100 - $eqBabaOusou->sommeTaux(),
                'pris_en_charge_par_usine'   => false,
                'is_active'                  => true,
            ],
        ];

        foreach ($vehicules as $data) {
            Vehicule::updateOrCreate(
                ['immatriculation' => $data['immatriculation'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id]
            );
        }
    }
}
