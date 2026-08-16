<?php

namespace Database\Seeders;

use App\Enums\CategorieVehicule;
use App\Models\Categorie;
use App\Models\EquipeLivraison;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule as TypeVehiculeModel;
use App\Models\Vehicule;
use Illuminate\Database\Seeder;

/**
 * Crée 10 véhicules (5 livraison_vente + 5 livraison_logistique) et les associe à leurs
 * équipes. Tout véhicule est rattaché à un site — voir VehiculeController::store()/update().
 *
 * Le type ne fixe plus la capacité (cf. TypeVehiculesSeeder) : chaque véhicule
 * porte sa propre capacite_packs, indépendamment des autres véhicules du même type.
 *
 * Catégorie (propriété, cf. CategorieVehicule) et usage (vente/logistique) sont deux axes
 * indépendants — ce jeu de données a juste PARTENAIRE sur le groupe "vente" et INTERNE sur le
 * groupe "logistique" par coïncidence de données de démo, jamais par règle.
 *
 * VENTE — PARTENAIRE (propriétaire tiers réel, opèrent pour un site) :
 * | Véhicule         | Type     | Équipe         | Immat      | Site     |
 * |------------------|----------|----------------|------------|----------|
 * | Nen Dow          | Camion   | Nen Dow        | RC-001-GN  | Matoto   |
 * | Kata Kata de Ali | Tricycle | Auto Dogomet   | TC-001-GN  | Lambanyi |
 * | Baba Ousou       | Minibus  | Baba Ousou     | VN-001-GN  | Matoto   |
 * | Kaloum Express   | Minibus  | Kaloum Express | KX-001-GN  | Sonfonia |
 * | Conakry 2        | Tricycle | Conakry 2      | TC-002-GN  | Matoto   |
 *
 * LOGISTIQUE — INTERNE (propriété de l'organisation) :
 * | Véhicule | Type     | Équipe           | Immat      | Site   |
 * |----------|----------|------------------|------------|--------|
 * | elm-1    | Minibus  | ELM Logistique 1 | ELM-001-GN | Matoto |
 * | elm-2    | Minibus  | ELM Logistique 2 | ELM-002-GN | Matoto |
 * | elm-3    | Camion   | ELM Logistique 3 | ELM-003-GN | Matoto |
 * | elm-4    | Minibus  | ELM Logistique 4 | ELM-004-GN | Matoto |
 * | Cousin   | Camion   | Cousin           | BK-4627-02 | Kouria |
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

        $lambanyi = Site::where('organization_id', $org->id)
            ->where('nom', 'Lambanyi')
            ->firstOrFail();

        $sonfonia = Site::where('organization_id', $org->id)
            ->where('nom', 'Sonfonia')
            ->firstOrFail();

        // Propriétaire par défaut des véhicules logistique — voir ProprietairesSeeder
        // et Proprietaire::interneParDefautId().
        $proprietaireInterne = Proprietaire::where('organization_id', $org->id)
            ->where('telephone', '+224622602693')
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
            // ── Vente (propriétaire privé) ──────────────────────────────────
            [
                'nom_vehicule' => 'Nen Dow',
                'marque' => 'Mercedes',
                'modele' => 'Actros',
                'immatriculation' => 'RC-001-GN',
                'type_vehicule_id' => $type('Camion'),
                'capacite_packs' => 500,
                'categorie' => CategorieVehicule::PARTENAIRE,
                'livraison_vente' => true,
                'livraison_logistique' => false,
                'site_id' => $matoto->id,
                'proprietaire_id' => $eqNenDow->proprietaire_id,
                'is_active' => true,
                'equipe' => $eqNenDow,
            ],
            [
                'nom_vehicule' => 'Kata Kata de Ali',
                'marque' => 'Bajaj',
                'modele' => 'RE',
                'immatriculation' => 'TC-001-GN',
                'type_vehicule_id' => $type('Tricycle'),
                'capacite_packs' => 80,
                'categorie' => CategorieVehicule::PARTENAIRE,
                'livraison_vente' => true,
                'livraison_logistique' => false,
                'site_id' => $lambanyi->id,
                'proprietaire_id' => $eqAutoDogomet->proprietaire_id,
                'is_active' => true,
                'equipe' => $eqAutoDogomet,
            ],
            [
                'nom_vehicule' => 'Baba Ousou',
                'marque' => 'Toyota',
                'modele' => 'HiAce',
                'immatriculation' => 'VN-001-GN',
                'type_vehicule_id' => $type('Minibus'),
                'capacite_packs' => 150,
                'categorie' => CategorieVehicule::PARTENAIRE,
                'livraison_vente' => true,
                'livraison_logistique' => false,
                'site_id' => $matoto->id,
                'proprietaire_id' => $eqBabaOusou->proprietaire_id,
                'is_active' => true,
                'equipe' => $eqBabaOusou,
            ],
            [
                'nom_vehicule' => 'Kaloum Express',
                'marque' => 'Toyota',
                'modele' => 'HiAce',
                'immatriculation' => 'KX-001-GN',
                'type_vehicule_id' => $type('Minibus'),
                'capacite_packs' => 120,
                'categorie' => CategorieVehicule::PARTENAIRE,
                'livraison_vente' => true,
                'livraison_logistique' => false,
                'site_id' => $sonfonia->id,
                'proprietaire_id' => $eqKaloumExpress->proprietaire_id,
                'is_active' => true,
                'equipe' => $eqKaloumExpress,
            ],
            [
                'nom_vehicule' => 'Conakry 2',
                'marque' => 'Bajaj',
                'modele' => 'RE',
                'immatriculation' => 'TC-002-GN',
                'type_vehicule_id' => $type('Tricycle'),
                'capacite_packs' => 60,
                'categorie' => CategorieVehicule::PARTENAIRE,
                'livraison_vente' => true,
                'livraison_logistique' => false,
                'site_id' => $matoto->id,
                'proprietaire_id' => $eqConakry2->proprietaire_id,
                'is_active' => true,
                'equipe' => $eqConakry2,
            ],

            // ── Logistique (propriété de l'organisation — site Matoto) ──────
            [
                'nom_vehicule' => 'elm-1',
                'marque' => 'Toyota',
                'modele' => 'HiLux',
                'immatriculation' => 'ELM-001-GN',
                'type_vehicule_id' => $type('Minibus'),
                'capacite_packs' => 120,
                'categorie' => CategorieVehicule::INTERNE,
                'livraison_vente' => false,
                'livraison_logistique' => true,
                'site_id' => $matoto->id,
                'proprietaire_id' => $proprietaireInterne->id,
                'is_active' => true,
                'equipe' => $eqElm1,
            ],
            [
                'nom_vehicule' => 'elm-2',
                'marque' => 'Renault',
                'modele' => 'Kangoo',
                'immatriculation' => 'ELM-002-GN',
                'type_vehicule_id' => $type('Minibus'),
                'capacite_packs' => 80,
                'categorie' => CategorieVehicule::INTERNE,
                'livraison_vente' => false,
                'livraison_logistique' => true,
                'site_id' => $matoto->id,
                'proprietaire_id' => $proprietaireInterne->id,
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
                'categorie' => CategorieVehicule::INTERNE,
                'livraison_vente' => false,
                'livraison_logistique' => true,
                'site_id' => $matoto->id,
                'proprietaire_id' => $proprietaireInterne->id,
                'is_active' => true,
                'equipe' => $eqElm3,
            ],
            [
                'nom_vehicule' => 'elm-4',
                'marque' => 'Toyota',
                'modele' => 'HiLux',
                'immatriculation' => 'ELM-004-GN',
                'type_vehicule_id' => $type('Minibus'),
                'capacite_packs' => 100,
                'categorie' => CategorieVehicule::INTERNE,
                'livraison_vente' => false,
                'livraison_logistique' => true,
                'site_id' => $matoto->id,
                'proprietaire_id' => $proprietaireInterne->id,
                'is_active' => true,
                'equipe' => $eqElm4,
            ],
            [
                'nom_vehicule' => 'Cousin',
                'marque' => null,
                'modele' => null,
                'immatriculation' => 'BK-4627-02',
                'type_vehicule_id' => $type('Camion'),
                'capacite_packs' => 200,
                'categorie' => CategorieVehicule::INTERNE,
                'livraison_vente' => false,
                'livraison_logistique' => true,
                'site_id' => $kouria->id,
                'proprietaire_id' => $proprietaireInterne->id,
                'is_active' => true,
                'equipe' => $eqCousin,
            ],
        ];

        // Catégorie "Sachet" — provisionnée par ProduitsSeeder (doit tourner avant ce seeder,
        // cf. DatabaseSeeder). Sert à faire basculer chaque véhicule de démo sur le régime "par
        // catégorie" de VehiculeCapaciteService, avec le même plafond que capacite_packs
        // ci-dessus (aucune donnée "Bouteille" fiable à seeder ici : reste à configurer via la
        // fiche véhicule — VehiculeCapacitesCard — une fois de vraies capacités connues).
        $sachet = Categorie::where('organization_id', $org->id)->where('nom', 'Sachet')->first();

        foreach ($vehicules as $data) {
            $equipeModel = $data['equipe'];
            unset($data['equipe']);

            $vehicule = Vehicule::updateOrCreate(
                ['immatriculation' => $data['immatriculation'], 'organization_id' => $org->id],
                [...$data, 'organization_id' => $org->id]
            );

            $equipeModel->update(['vehicule_id' => $vehicule->id]);

            if ($sachet && $data['capacite_packs'] !== null) {
                $vehicule->capacites()->updateOrCreate(
                    ['categorie_id' => $sachet->id],
                    ['organization_id' => $org->id, 'capacite_max' => $data['capacite_packs']]
                );
            }
        }
    }
}
