<?php

namespace Database\Seeders;

use App\Enums\TypeVehicule;
use App\Models\EquipeLivraison;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use Illuminate\Database\Seeder;

/**
 * Crée 7 véhicules.
 *
 * Règle taux : taux_commission_proprietaire + Σ taux équipe = 100 pour chaque véhicule avec commission active.
 *
 * | Véhicule       | Type          | Propriétaire      | Équipe         | Taux prop | Σ équipe | Total |
 * |----------------|---------------|-------------------|----------------|-----------|----------|-------|
 * | Camion Alpha   | camion        | Mamadou BARRY     | Équipe Nord    |   92 %    |    8 %   | 100 % |
 * | Tricycle 01    | tricycle      | Fatoumata DIALLO  | Équipe Sud     |   94 %    |    6 %   | 100 % |
 * | Vanne Express  | camionnette   | Mamadou BARRY     | Équipe Est     |   92 %    |    8 %   | 100 % |
 * | Camion Bêta    | camion        | Issa TOUNKARA     | Équipe Ouest   |   91 %    |    9 %   | 100 % |
 * | Tricycle 02    | tricycle      | Saran CONDÉ       | Équipe Centre  |   90 %    |   10 %   | 100 % |
 * | Tricycle 03    | tricycle      | Issa TOUNKARA     | Équipe Nord    |   92 %    |    8 %   | 100 % |
 * | Pickup ELM     | voiture       | Mamadou BARRY     | (aucune)       | commission désactivée, pris en charge usine |
 */
class VehiculesSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        // ── Helpers de lookup ─────────────────────────────────────────────────
        $prop   = fn (string $tel) => Proprietaire::where('telephone', $tel)
            ->where('organization_id', $org->id)
            ->firstOrFail();

        $equipe = fn (string $nom) => EquipeLivraison::where('nom', $nom)
            ->where('organization_id', $org->id)
            ->firstOrFail();

        $mamadouBarry    = $prop('+224621000001');
        $fatoumataDiallo = $prop('+224621000002');
        $issaTounkara    = $prop('+224621000003');
        $saranConde      = $prop('+224621000004');

        $eqNord   = $equipe('Équipe Nord');
        $eqSud    = $equipe('Équipe Sud');
        $eqEst    = $equipe('Équipe Est');
        $eqOuest  = $equipe('Équipe Ouest');
        $eqCentre = $equipe('Équipe Centre');

        $vehicules = [
            [
                'nom_vehicule'                => 'Camion Alpha',
                'marque'                      => 'Mercedes',
                'modele'                      => 'Actros',
                'immatriculation'             => 'RC-001-GN',
                'type_vehicule'               => TypeVehicule::CAMION->value,
                'capacite_packs'              => 500,
                'proprietaire_id'             => $mamadouBarry->id,
                'equipe_livraison_id'         => $eqNord->id,
                'taux_commission_proprietaire'=> 92.00,
                'commission_active'           => true,
                'pris_en_charge_par_usine'    => false,
                'is_active'                   => true,
            ],
            [
                'nom_vehicule'                => 'Tricycle 01',
                'marque'                      => 'Bajaj',
                'modele'                      => 'RE',
                'immatriculation'             => 'TC-001-GN',
                'type_vehicule'               => TypeVehicule::TRICYCLE->value,
                'capacite_packs'              => 80,
                'proprietaire_id'             => $fatoumataDiallo->id,
                'equipe_livraison_id'         => $eqSud->id,
                'taux_commission_proprietaire'=> 94.00,
                'commission_active'           => true,
                'pris_en_charge_par_usine'    => false,
                'is_active'                   => true,
            ],
            [
                'nom_vehicule'                => 'Vanne Express',
                'marque'                      => 'Toyota',
                'modele'                      => 'HiAce',
                'immatriculation'             => 'VN-001-GN',
                'type_vehicule'               => TypeVehicule::CAMIONNETTE->value,
                'capacite_packs'              => 150,
                'proprietaire_id'             => $mamadouBarry->id,
                'equipe_livraison_id'         => $eqEst->id,
                'taux_commission_proprietaire'=> 92.00,
                'commission_active'           => true,
                'pris_en_charge_par_usine'    => false,
                'is_active'                   => true,
            ],
            [
                'nom_vehicule'                => 'Camion Bêta',
                'marque'                      => 'MAN',
                'modele'                      => 'TGX',
                'immatriculation'             => 'RC-002-GN',
                'type_vehicule'               => TypeVehicule::CAMION->value,
                'capacite_packs'              => 400,
                'proprietaire_id'             => $issaTounkara->id,
                'equipe_livraison_id'         => $eqOuest->id,
                'taux_commission_proprietaire'=> 91.00,
                'commission_active'           => true,
                'pris_en_charge_par_usine'    => false,
                'is_active'                   => true,
            ],
            [
                'nom_vehicule'                => 'Tricycle 02',
                'marque'                      => 'TVS',
                'modele'                      => 'King',
                'immatriculation'             => 'TC-002-GN',
                'type_vehicule'               => TypeVehicule::TRICYCLE->value,
                'capacite_packs'              => 100,
                'proprietaire_id'             => $saranConde->id,
                'equipe_livraison_id'         => $eqCentre->id,
                'taux_commission_proprietaire'=> 90.00,
                'commission_active'           => true,
                'pris_en_charge_par_usine'    => false,
                'is_active'                   => true,
            ],
            [
                'nom_vehicule'                => 'Tricycle 03',
                'marque'                      => 'Bajaj',
                'modele'                      => 'RE Diesel',
                'immatriculation'             => 'TC-003-GN',
                'type_vehicule'               => TypeVehicule::TRICYCLE->value,
                'capacite_packs'              => 80,
                'proprietaire_id'             => $issaTounkara->id,
                'equipe_livraison_id'         => $eqNord->id,
                'taux_commission_proprietaire'=> 92.00,
                'commission_active'           => true,
                'pris_en_charge_par_usine'    => false,
                'is_active'                   => true,
            ],
            [
                // Véhicule usine sans commission, pour cas réels où l'usine prend en charge
                'nom_vehicule'                => 'Pickup ELM',
                'marque'                      => 'Toyota',
                'modele'                      => 'Hilux',
                'immatriculation'             => 'PK-001-GN',
                'type_vehicule'               => TypeVehicule::VOITURE->value,
                'capacite_packs'              => 40,
                'proprietaire_id'             => $mamadouBarry->id,
                'equipe_livraison_id'         => null,
                'taux_commission_proprietaire'=> 0.00,
                'commission_active'           => false,
                'pris_en_charge_par_usine'    => true,
                'is_active'                   => true,
            ],
        ];

        foreach ($vehicules as $data) {
            Vehicule::firstOrCreate(
                ['immatriculation' => $data['immatriculation'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id]
            );
        }
    }
}
