<?php

namespace Database\Seeders;

use App\Enums\TypeVehicule;
use App\Models\EquipeLivraison;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use Illuminate\Database\Seeder;

/**
 * Cree 3 vehicules.
 *
 * Regle taux: taux_commission_proprietaire + somme taux equipe = 100.
 *
 * | Vehicule          | Type        | Proprietaire      | Equipe       | Taux prop | Somme equipe |
 * |-------------------|-------------|-------------------|--------------|-----------|--------------|
 * | Nen Dow           | camion      | Mamadou BARRY     | Nen Dow      | 60 %      | 40 %         |
 * | Kata Kata de Ali  | tricycle    | Fatoumata DIALLO  | Auto Dogomet | 60 %      | 40 %         |
 * | Baba Ousou        | camionnette | Mamadou BARRY     | Baba Ousou   | 60 %      | 40 %         |
 */
class VehiculesSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $prop = fn (string $tel) => Proprietaire::query()
            ->where('telephone', $tel)
            ->where('organization_id', $org->id)
            ->firstOrFail();

        $equipe = fn (string $nom) => EquipeLivraison::query()
            ->where('nom', $nom)
            ->where('organization_id', $org->id)
            ->firstOrFail();

        $mamadouBarry    = $prop('+224621000001');
        $fatoumataDiallo = $prop('+224621000002');

        $eqNenDow      = $equipe('Nen Dow');
        $eqAutoDogomet = $equipe('Auto Dogomet');
        $eqBabaOusou   = $equipe('Baba Ousou');

        $vehicules = [
            [
                'nom_vehicule'                 => 'Nen Dow',
                'marque'                       => 'Mercedes',
                'modele'                       => 'Actros',
                'immatriculation'              => 'RC-001-GN',
                'type_vehicule'                => TypeVehicule::CAMION->value,
                'capacite_packs'               => 500,
                'proprietaire_id'              => $mamadouBarry->id,
                'equipe_livraison_id'          => $eqNenDow->id,
                'taux_commission_proprietaire' => 100 - $eqNenDow->sommeTaux(),
                'pris_en_charge_par_usine'     => false,
                'is_active'                    => true,
            ],
            [
                'nom_vehicule'                 => 'Kata Kata de Ali',
                'marque'                       => 'Bajaj',
                'modele'                       => 'RE',
                'immatriculation'              => 'TC-001-GN',
                'type_vehicule'                => TypeVehicule::TRICYCLE->value,
                'capacite_packs'               => 80,
                'proprietaire_id'              => $fatoumataDiallo->id,
                'equipe_livraison_id'          => $eqAutoDogomet->id,
                'taux_commission_proprietaire' => 100 - $eqAutoDogomet->sommeTaux(),
                'pris_en_charge_par_usine'     => false,
                'is_active'                    => true,
            ],
            [
                'nom_vehicule'                 => 'Baba Ousou',
                'marque'                       => 'Toyota',
                'modele'                       => 'HiAce',
                'immatriculation'              => 'VN-001-GN',
                'type_vehicule'                => TypeVehicule::CAMIONNETTE->value,
                'capacite_packs'               => 150,
                'proprietaire_id'              => $mamadouBarry->id,
                'equipe_livraison_id'          => $eqBabaOusou->id,
                'taux_commission_proprietaire' => 100 - $eqBabaOusou->sommeTaux(),
                'pris_en_charge_par_usine'     => false,
                'is_active'                    => true,
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

