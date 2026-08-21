<?php

namespace Database\Seeders\Organizations\ElmV2Demo;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Models\CommissionProcessus;
use App\Models\Organization;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Organisation de démonstration "Eau La Maman V2 Demo" — seule organisation
 * du projet avec le moteur de commissions V2 explicitement activé
 * (CommissionProcessus code=vente, statut=ACTIF). Totalement indépendante de
 * "elm" (qui reste Legacy) pour ne jamais faire basculer les specs E2E
 * existantes vers l'UI V2 — cf. MoteurCommissionResolver::estV2().
 *
 * Ne jamais utiliser Organization::first() : toujours retrouver ou créer via
 * le slug 'elm-v2-demo', comme tous les seeders du projet le font (cf.
 * FelloDemoOrganizationSeeder).
 */
class ElmV2DemoOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::firstOrCreate(
            ['slug' => 'elm-v2-demo'],
            ['name' => 'Eau La Maman V2 Demo', 'is_active' => true]
        );

        foreach (['admin_entreprise'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        // Obligatoire : un produit ne peut pas exister sans type.
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);

        // LE switch V2 — cf. MoteurCommissionResolver::estV2(). declencheur est
        // informatif sur ce modèle (le vrai déclencheur vient de
        // Parametre::getDeclencheurCommissionVente(), qui vaut déjà
        // FACTURE_ENCAISSEE par défaut — cf. parcours E2E cible : vente
        // encaissée déclenche la génération).
        CommissionProcessus::updateOrCreate(
            ['organization_id' => $org->id, 'code' => CommissionProcessus::CODE_VENTE],
            [
                'libelle' => 'Vente',
                'declencheur' => 'facture_encaissee',
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
                'statut' => CommissionActivationStatut::ACTIF->value,
            ]
        );

        $this->command->info("✓ Organisation « Eau La Maman V2 Demo » prête (slug: elm-v2-demo, id: {$org->id}, moteur V2 actif).");
    }
}
