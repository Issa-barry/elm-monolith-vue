<?php

namespace Database\Seeders;

use App\Enums\TypeVehicule;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use Illuminate\Database\Seeder;

class VehiculesSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        // ── Livreurs ──────────────────────────────────────────────────────────
        $livreurs = [
            [
                'nom'       => 'Camara',
                'prenom'    => 'Ibrahima',
                'telephone' => '+224622000001',
                'adresse'   => 'Matoto, Conakry',
                'is_active' => true,
            ],
            [
                'nom'       => 'Kouyaté',
                'prenom'    => 'Sékou',
                'telephone' => '+224622000002',
                'adresse'   => 'Dixinn, Conakry',
                'is_active' => true,
            ],
            [
                'nom'       => 'Bah',
                'prenom'    => 'Mariama',
                'telephone' => '+224622000003',
                'adresse'   => 'Kaloum, Conakry',
                'is_active' => true,
            ],
        ];

        $livreurModels = [];
        foreach ($livreurs as $data) {
            $livreurModels[] = Livreur::firstOrCreate(
                ['telephone' => $data['telephone'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id]
            );
        }

        // ── Propriétaires ─────────────────────────────────────────────────────
        $proprietaires = [
            [
                'nom'       => 'Barry',
                'prenom'    => 'Mamadou',
                'telephone' => '+224621000001',
                'adresse'   => 'Kaloum, Conakry',
                'is_active' => true,
            ],
            [
                'nom'       => 'Diallo',
                'prenom'    => 'Fatoumata',
                'telephone' => '+224621000002',
                'adresse'   => 'Ratoma, Conakry',
                'is_active' => true,
            ],
        ];

        $proprietaireModels = [];
        foreach ($proprietaires as $data) {
            $proprietaireModels[] = Proprietaire::firstOrCreate(
                ['telephone' => $data['telephone'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id]
            );
        }

        // ── Véhicules ─────────────────────────────────────────────────────────
        $vehicules = [
            [
                'nom_vehicule'                 => 'Camion Alpha',
                'marque'                       => 'Mercedes',
                'modele'                       => 'Actros',
                'immatriculation'              => 'RC-001-GN',
                'type_vehicule'                => TypeVehicule::CAMION->value,
                'capacite_packs'               => 500,
                'proprietaire_id'              => $proprietaireModels[0]->id,
                'livreur_principal_id'         => $livreurModels[0]->id,
                'pris_en_charge_par_usine'     => false,
                'taux_commission_livreur'      => 5.00,
                'taux_commission_proprietaire' => 10.00,
                'commission_active'            => true,
                'is_active'                    => true,
            ],
            [
                'nom_vehicule'                 => 'Tricycle 01',
                'marque'                       => 'Bajaj',
                'modele'                       => 'RE',
                'immatriculation'              => 'TC-001-GN',
                'type_vehicule'                => TypeVehicule::TRICYCLE->value,
                'capacite_packs'               => 80,
                'proprietaire_id'              => $proprietaireModels[1]->id,
                'livreur_principal_id'         => $livreurModels[1]->id,
                'pris_en_charge_par_usine'     => false,
                'taux_commission_livreur'      => 5.00,
                'taux_commission_proprietaire' => 8.00,
                'commission_active'            => true,
                'is_active'                    => true,
            ],
            [
                'nom_vehicule'                 => 'Vanne Express',
                'marque'                       => 'Toyota',
                'modele'                       => 'HiAce',
                'immatriculation'              => 'VN-001-GN',
                'type_vehicule'                => TypeVehicule::CAMIONNETTE->value,
                'capacite_packs'               => 150,
                'proprietaire_id'              => $proprietaireModels[0]->id,
                'livreur_principal_id'         => $livreurModels[2]->id,
                'pris_en_charge_par_usine'     => true,
                'taux_commission_livreur'      => 5.00,
                'taux_commission_proprietaire' => 0.00,
                'commission_active'            => true,
                'is_active'                    => true,
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
