<?php

namespace Database\Seeders\Organizations\ElmV2Demo;

use App\Enums\CategorieTarifaireVehicule;
use App\Enums\CategorieVehicule;
use App\Enums\PrestataireType;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use Illuminate\Database\Seeder;

/**
 * Fixtures dédiées à tests/e2e/commissions/*.spec.ts, en complément
 * d'ElmV2DemoFleetSeeder (qui reste inchangé — utilisé aussi par
 * commission-v2-full-chain.spec.ts, commission-site-flow.spec.ts,
 * commission-consultant-flow.spec.ts). Ajoute, sans jamais toucher à l'existant :
 *
 * - un second véhicule de type Tricycle (le seul type réel du catalogue standard
 *   qui existait déjà avant ce chantier, cf. TypeVehiculesSeeder), avec son propre
 *   propriétaire et son propre livreur — nécessaire pour C08 (isolation) et pour
 *   la priorité section 11 (barème global vs exception véhicule) ;
 * - un consultant actif, nécessaire dès qu'un scénario coche la cible Consultant
 *   (StoreCommissionConfigurationRequest exige consultant_id).
 */
class ElmV2DemoCommissionSmokeFixturesSeeder extends Seeder
{
    public const IMMATRICULATION_TRICYCLE = 'V2-DEMO-TRI-01';

    public const LIVREUR_TRICYCLE_TELEPHONE = '+224600000221';

    public const CONSULTANT_NOM = 'Consultant V2 Demo';

    public function run(): void
    {
        $org = Organization::where('slug', 'elm-v2-demo')->firstOrFail();

        $typeTricycle = TypeVehicule::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Tricycle V2 Demo'],
            ['is_active' => true, 'categorie_tarifaire' => CategorieTarifaireVehicule::TRICYCLE]
        );

        $personneProprio = Personne::resoudreOuCreer($org->id, [
            'prenom' => 'Propriétaire',
            'nom' => 'Tricycle V2 Demo',
            'telephone' => '+224600000220',
            'code_pays' => 'GN',
            'code_phone_pays' => '+224',
            'pays' => 'Guinée',
            'ville' => 'Conakry',
        ]);
        $proprietaireTricycle = Proprietaire::firstOrCreate(
            ['organization_id' => $org->id, 'personne_id' => $personneProprio->id],
            ['is_active' => true]
        );

        $vehiculeTricycle = Vehicule::firstOrCreate(
            ['organization_id' => $org->id, 'immatriculation' => self::IMMATRICULATION_TRICYCLE],
            [
                'nom_vehicule' => 'Tricycle V2 Demo',
                'type_vehicule_id' => $typeTricycle->id,
                'capacite_packs' => 150,
                'proprietaire_id' => $proprietaireTricycle->id,
                'categorie' => CategorieVehicule::INTERNE,
                'livraison_vente' => true,
                'livraison_logistique' => false,
                'is_active' => true,
            ]
        );

        $personneLivreur = Personne::resoudreOuCreer($org->id, [
            'prenom' => 'Chauffeur',
            'nom' => 'Tricycle V2 Demo',
            'telephone' => self::LIVREUR_TRICYCLE_TELEPHONE,
            'code_pays' => 'GN',
            'code_phone_pays' => '+224',
            'pays' => 'Guinée',
            'ville' => 'Conakry',
        ]);
        $livreurTricycle = Livreur::firstOrCreate(
            ['organization_id' => $org->id, 'personne_id' => $personneLivreur->id],
            ['nom_complet' => 'Chauffeur Tricycle V2 Demo', 'is_active' => true]
        );

        $equipeTricycle = EquipeLivraison::firstOrCreate(
            ['organization_id' => $org->id, 'vehicule_id' => $vehiculeTricycle->id],
            ['is_active' => true]
        );

        EquipeLivreur::firstOrCreate(
            ['equipe_id' => $equipeTricycle->id, 'livreur_id' => $livreurTricycle->id],
            ['role' => 'chauffeur', 'ordre' => 0]
        );

        $personneConsultant = Personne::resoudreOuCreer($org->id, [
            'prenom' => 'Consultant',
            'nom' => 'V2 Demo',
            'telephone' => '+224600000230',
            'code_pays' => 'GN',
            'code_phone_pays' => '+224',
            'pays' => 'Guinée',
            'ville' => 'Conakry',
        ]);
        Prestataire::firstOrCreate(
            ['organization_id' => $org->id, 'personne_id' => $personneConsultant->id],
            ['type' => PrestataireType::CONSULTANT->value, 'is_active' => true]
        );

        $this->command->info('✓ Tricycle '.self::IMMATRICULATION_TRICYCLE.' + équipe + consultant actif prêts.');
    }
}
