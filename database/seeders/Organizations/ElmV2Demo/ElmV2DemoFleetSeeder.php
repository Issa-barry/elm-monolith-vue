<?php

namespace Database\Seeders\Organizations\ElmV2Demo;

use App\Enums\CategorieVehicule;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use Illuminate\Database\Seeder;

/**
 * Véhicule + équipe minimale, volontairement SANS aucun partage Livraison
 * par catégorie configuré (equipe_livraison_partages_categorie) : le
 * parcours E2E cible configure ce partage lui-même via la popup équipe
 * (étape "Partage"), c'est justement ce que le test doit vérifier.
 *
 * Propriétaire interne par défaut de l'organisation (pas un tiers) — cohérent
 * avec categorie=INTERNE, pour que la commission propriétaire de la démo
 * profite au même compte que celui utilisé pour valider/payer.
 */
class ElmV2DemoFleetSeeder extends Seeder
{
    public const IMMATRICULATION = 'V2-DEMO-01';

    public const LIVREUR_TELEPHONE = '+224600000211';

    public function run(): void
    {
        $org = Organization::where('slug', 'elm-v2-demo')->firstOrFail();

        $typeVehicule = TypeVehicule::firstOrCreate(
            ['organization_id' => $org->id, 'nom' => 'Minibus V2 Demo'],
            ['capacite_defaut' => 500, 'is_active' => true]
        );

        $personneProprio = Personne::resoudreOuCreer($org->id, [
            'prenom' => 'Propriétaire',
            'nom' => 'V2 Demo',
            'telephone' => '+224600000210',
            'code_pays' => 'GN',
            'code_phone_pays' => '+224',
            'pays' => 'Guinée',
            'ville' => 'Conakry',
        ]);
        $proprietaire = Proprietaire::firstOrCreate(
            ['organization_id' => $org->id, 'personne_id' => $personneProprio->id],
            ['is_active' => true]
        );
        if ($org->proprietaire_interne_id !== $proprietaire->id) {
            $org->forceFill(['proprietaire_interne_id' => $proprietaire->id])->save();
        }

        $vehicule = Vehicule::firstOrCreate(
            ['organization_id' => $org->id, 'immatriculation' => self::IMMATRICULATION],
            [
                'nom_vehicule' => 'Véhicule V2 Demo',
                'type_vehicule_id' => $typeVehicule->id,
                'capacite_packs' => 500,
                'proprietaire_id' => $proprietaire->id,
                'categorie' => CategorieVehicule::INTERNE,
                'livraison_vente' => true,
                'livraison_logistique' => false,
                'is_active' => true,
            ]
        );

        $personneLivreur = Personne::resoudreOuCreer($org->id, [
            'prenom' => 'Chauffeur',
            'nom' => 'V2 Demo',
            'telephone' => self::LIVREUR_TELEPHONE,
            'code_pays' => 'GN',
            'code_phone_pays' => '+224',
            'pays' => 'Guinée',
            'ville' => 'Conakry',
        ]);
        $livreur = Livreur::firstOrCreate(
            ['organization_id' => $org->id, 'personne_id' => $personneLivreur->id],
            ['nom_complet' => 'Chauffeur V2 Demo', 'is_active' => true]
        );

        $equipe = EquipeLivraison::firstOrCreate(
            ['organization_id' => $org->id, 'vehicule_id' => $vehicule->id],
            ['is_active' => true]
        );

        EquipeLivreur::firstOrCreate(
            ['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id],
            ['role' => 'chauffeur', 'ordre' => 0]
        );

        $this->command->info('✓ Véhicule '.self::IMMATRICULATION.' + équipe (1 chauffeur, sans partage configuré) prêts.');
    }
}
