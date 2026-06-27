<?php

namespace Database\Seeders;

use App\Models\EquipeLivraison;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TypeVehicule as TypeVehiculeModel;
use App\Models\Vehicule;
use Illuminate\Database\Seeder;

/**
 * Crée 10 véhicules (5 externes + 5 internes) et les associe à leurs équipes.
 *
 * EXTERNES (appartiennent à un propriétaire privé) :
 * | Véhicule         | Type         | Équipe         | Immat      |
 * |------------------|--------------|----------------|------------|
 * | Nen Dow          | Camion       | Nen Dow        | RC-001-GN  |
 * | Kata Kata de Ali | Tricycle moto| Auto Dogomet   | TC-001-GN  |
 * | Baba Ousou       | Minibus 200  | Baba Ousou     | VN-001-GN  |
 * | Kaloum Express   | Minibus 200  | Kaloum Express | KX-001-GN  |
 * | Conakry 2        | Tricycle moto| Conakry 2      | TC-002-GN  |
 *
 * INTERNES (appartiennent à l'organisation — 100 % livreurs) :
 * | Véhicule | Type        | Équipe           | Immat      | Site   |
 * |----------|-------------|------------------|------------|--------|
 * | elm-1    | Minibus 200 | ELM Logistique 1 | ELM-001-GN | Matoto |
 * | elm-2    | Minibus 200 | ELM Logistique 2 | ELM-002-GN | Matoto |
 * | elm-3    | Camion      | ELM Logistique 3 | ELM-003-GN | Matoto |
 * | elm-4    | Minibus 200 | ELM Logistique 4 | ELM-004-GN | Matoto |
 * | Cousin   | —           | Cousin           | BK-4627-02 | Kouria |
 */
class VehiculesSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        $typeIds = TypeVehiculeModel::where('organization_id', $org->id)
            ->get()
            ->keyBy(fn ($t) => mb_strtolower($t->nom))
            ->map(fn ($t) => $t->id);

        $type = fn (string $nom) => $typeIds[mb_strtolower($nom)] ?? null;

        $matoto = Site::where('organization_id', $org->id)
            ->where('nom', 'Matoto')
            ->firstOrFail();

        $kouria = Site::where('organization_id', $org->id)
            ->where('nom', 'Kouria')
            ->firstOrFail();

        $equipeParChauffeur = fn (string $tel) => EquipeLivraison::query()
            ->where('organization_id', $org->id)
            ->whereHas('membres', fn ($q) => $q
                ->where('role', 'chauffeur')
                ->whereHas('livreur', fn ($q2) => $q2
                    ->where('telephone', $tel)
                    ->where('organization_id', $org->id)
                )
            )
            ->firstOrFail();

        $eqNenDow = $equipeParChauffeur('+224622000001');
        $eqAutoDogomet = $equipeParChauffeur('+224622000003');
        $eqBabaOusou = $equipeParChauffeur('+224622000008');
        $eqKaloumExpress = $equipeParChauffeur('+224622000004');
        $eqConakry2 = $equipeParChauffeur('+224622000006');
        $eqElm1 = $equipeParChauffeur('+224622000011');
        $eqElm2 = $equipeParChauffeur('+224622000012');
        $eqElm3 = $equipeParChauffeur('+224622000014');
        $eqElm4 = $equipeParChauffeur('+224622000007');
        $eqCousin = $equipeParChauffeur('+224621346981');

        $vehicules = [
            // ── Externes ────────────────────────────────────────────────────
            [
                'nom_vehicule' => 'Nen Dow',
                'marque' => 'Mercedes',
                'modele' => 'Actros',
                'immatriculation' => 'RC-001-GN',
                'type_vehicule_id' => $type('Camion'),
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
                'type_vehicule_id' => $type('Tricycle moto'),
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
                'type_vehicule_id' => $type('Minibus 200'),
                'capacite_packs' => 150,
                'categorie' => 'externe',
                'proprietaire_id' => $eqBabaOusou->proprietaire_id,
                'pris_en_charge_par_usine' => false,
                'is_active' => true,
                'equipe' => $eqBabaOusou,
            ],
            [
                'nom_vehicule' => 'Kaloum Express',
                'marque' => 'Toyota',
                'modele' => 'HiAce',
                'immatriculation' => 'KX-001-GN',
                'type_vehicule_id' => $type('Minibus 200'),
                'capacite_packs' => 120,
                'categorie' => 'externe',
                'proprietaire_id' => $eqKaloumExpress->proprietaire_id,
                'pris_en_charge_par_usine' => false,
                'is_active' => true,
                'equipe' => $eqKaloumExpress,
            ],
            [
                'nom_vehicule' => 'Conakry 2',
                'marque' => 'Bajaj',
                'modele' => 'RE',
                'immatriculation' => 'TC-002-GN',
                'type_vehicule_id' => $type('Tricycle moto'),
                'capacite_packs' => 60,
                'categorie' => 'externe',
                'proprietaire_id' => $eqConakry2->proprietaire_id,
                'pris_en_charge_par_usine' => false,
                'is_active' => true,
                'equipe' => $eqConakry2,
            ],

            // ── Internes (propriété de l'organisation — site Matoto) ─────────
            [
                'nom_vehicule' => 'elm-1',
                'marque' => 'Toyota',
                'modele' => 'HiLux',
                'immatriculation' => 'ELM-001-GN',
                'type_vehicule_id' => $type('Minibus 200'),
                'capacite_packs' => 120,
                'categorie' => 'interne',
                'site_id' => $matoto->id,
                'proprietaire_id' => null,
                'pris_en_charge_par_usine' => true,
                'is_active' => true,
                'equipe' => $eqElm1,
            ],
            [
                'nom_vehicule' => 'elm-2',
                'marque' => 'Renault',
                'modele' => 'Kangoo',
                'immatriculation' => 'ELM-002-GN',
                'type_vehicule_id' => $type('Minibus 200'),
                'capacite_packs' => 80,
                'categorie' => 'interne',
                'site_id' => $matoto->id,
                'proprietaire_id' => null,
                'pris_en_charge_par_usine' => true,
                'is_active' => true,
                'equipe' => $eqElm2,
            ],
            [
                'nom_vehicule' => 'elm-3',
                'marque' => 'Mercedes',
                'modele' => 'Sprinter',
                'immatriculation' => 'ELM-003-GN',
                'type_vehicule_id' => $type('Camion'),
                'capacite_packs' => 300,
                'categorie' => 'interne',
                'site_id' => $matoto->id,
                'proprietaire_id' => null,
                'pris_en_charge_par_usine' => true,
                'is_active' => true,
                'equipe' => $eqElm3,
            ],
            [
                'nom_vehicule' => 'elm-4',
                'marque' => 'Toyota',
                'modele' => 'HiLux',
                'immatriculation' => 'ELM-004-GN',
                'type_vehicule_id' => $type('Minibus 200'),
                'capacite_packs' => 100,
                'categorie' => 'interne',
                'site_id' => $matoto->id,
                'proprietaire_id' => null,
                'pris_en_charge_par_usine' => true,
                'is_active' => true,
                'equipe' => $eqElm4,
            ],
            [
                'nom_vehicule' => 'Cousin',
                'marque' => null,
                'modele' => null,
                'immatriculation' => 'BK-4627-02',
                'type_vehicule_id' => null,
                'capacite_packs' => 200,
                'categorie' => 'interne',
                'site_id' => $kouria->id,
                'proprietaire_id' => null,
                'pris_en_charge_par_usine' => true,
                'is_active' => true,
                'equipe' => $eqCousin,
            ],
        ];

        foreach ($vehicules as $data) {
            $equipeModel = $data['equipe'];
            unset($data['equipe']);

            $vehicule = Vehicule::updateOrCreate(
                ['immatriculation' => $data['immatriculation'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id]
            );

            $equipeModel->update(['vehicule_id' => $vehicule->id]);
        }
    }
}
