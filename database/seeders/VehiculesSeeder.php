<?php

namespace Database\Seeders;

use App\Enums\TypeVehicule;
use App\Models\EquipeLivraison;
use App\Models\Organization;
use App\Models\Vehicule;
use Illuminate\Database\Seeder;

/**
 * Crée 6 véhicules (3 externes + 3 internes) et les associe à leurs équipes.
 *
 * EXTERNES (appartiennent à un propriétaire privé) :
 * | Véhicule         | Type        | Équipe       | Immat      |
 * |------------------|-------------|--------------|------------|
 * | Nen Dow          | camion      | Nen Dow      | RC-001-GN  |
 * | Kata Kata de Ali | tricycle    | Auto Dogomet | TC-001-GN  |
 * | Baba Ousou       | camionnette | Baba Ousou   | VN-001-GN  |
 *
 * INTERNES (appartiennent à l'organisation — 100 % livreurs) :
 * | Véhicule | Type        | Équipe           | Immat      |
 * |----------|-------------|------------------|------------|
 * | elm-1    | camionnette | ELM Logistique 1 | ELM-001-GN |
 * | elm-2    | camionnette | ELM Logistique 2 | ELM-002-GN |
 * | elm-3    | camion      | ELM Logistique 3 | ELM-003-GN |
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

        $eqElm1 = $equipe('ELM Logistique 1');
        $eqElm2 = $equipe('ELM Logistique 2');
        $eqElm3 = $equipe('ELM Logistique 3');

        $vehicules = [
            // ── Externes ────────────────────────────────────────────────────
            [
                'nom_vehicule'            => 'Nen Dow',
                'marque'                  => 'Mercedes',
                'modele'                  => 'Actros',
                'immatriculation'         => 'RC-001-GN',
                'type_vehicule'           => TypeVehicule::CAMION->value,
                'capacite_packs'          => 500,
                'categorie'               => 'externe',
                'proprietaire_id'         => $eqNenDow->proprietaire_id,
                'pris_en_charge_par_usine' => false,
                'is_active'               => true,
                'equipe'                  => $eqNenDow,
            ],
            [
                'nom_vehicule'            => 'Kata Kata de Ali',
                'marque'                  => 'Bajaj',
                'modele'                  => 'RE',
                'immatriculation'         => 'TC-001-GN',
                'type_vehicule'           => TypeVehicule::TRICYCLE->value,
                'capacite_packs'          => 80,
                'categorie'               => 'externe',
                'proprietaire_id'         => $eqAutoDogomet->proprietaire_id,
                'pris_en_charge_par_usine' => false,
                'is_active'               => true,
                'equipe'                  => $eqAutoDogomet,
            ],
            [
                'nom_vehicule'            => 'Baba Ousou',
                'marque'                  => 'Toyota',
                'modele'                  => 'HiAce',
                'immatriculation'         => 'VN-001-GN',
                'type_vehicule'           => TypeVehicule::CAMIONNETTE->value,
                'capacite_packs'          => 150,
                'categorie'               => 'externe',
                'proprietaire_id'         => $eqBabaOusou->proprietaire_id,
                'pris_en_charge_par_usine' => false,
                'is_active'               => true,
                'equipe'                  => $eqBabaOusou,
            ],

            // ── Internes (propriété de l'organisation) ───────────────────────
            [
                'nom_vehicule'            => 'elm-1',
                'marque'                  => 'Toyota',
                'modele'                  => 'HiLux',
                'immatriculation'         => 'ELM-001-GN',
                'type_vehicule'           => TypeVehicule::CAMIONNETTE->value,
                'capacite_packs'          => 120,
                'categorie'               => 'interne',
                'proprietaire_id'         => null,
                'pris_en_charge_par_usine' => true,
                'is_active'               => true,
                'equipe'                  => $eqElm1,
            ],
            [
                'nom_vehicule'            => 'elm-2',
                'marque'                  => 'Renault',
                'modele'                  => 'Kangoo',
                'immatriculation'         => 'ELM-002-GN',
                'type_vehicule'           => TypeVehicule::CAMIONNETTE->value,
                'capacite_packs'          => 80,
                'categorie'               => 'interne',
                'proprietaire_id'         => null,
                'pris_en_charge_par_usine' => true,
                'is_active'               => true,
                'equipe'                  => $eqElm2,
            ],
            [
                'nom_vehicule'            => 'elm-3',
                'marque'                  => 'Mercedes',
                'modele'                  => 'Sprinter',
                'immatriculation'         => 'ELM-003-GN',
                'type_vehicule'           => TypeVehicule::CAMION->value,
                'capacite_packs'          => 300,
                'categorie'               => 'interne',
                'proprietaire_id'         => null,
                'pris_en_charge_par_usine' => true,
                'is_active'               => true,
                'equipe'                  => $eqElm3,
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
