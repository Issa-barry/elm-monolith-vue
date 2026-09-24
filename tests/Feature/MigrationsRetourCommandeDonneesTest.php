<?php

namespace Tests\Feature;

use App\Models\CompteMapping;
use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Migrations de DONNÉES du retour de livraison (23/09/2026) : permission `ventes.enregistrer_retour`
 * accordée aux rôles qui constatent déjà la livraison, et mappings comptables `vente_retour`
 * provisionnés pour les organisations existantes. Toutes deux doivent être non destructives et
 * rejouables sans effet de bord.
 */
class MigrationsRetourCommandeDonneesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PERMISSION = '2026_09_23_100100_backfill_ventes_enregistrer_retour_permission.php';

    private const MIGRATION_COMPTA = '2026_09_23_100200_backfill_compta_mapping_vente_retour.php';

    public function test_permission_accordee_uniquement_aux_roles_qui_valident_la_reception(): void
    {
        Permission::firstOrCreate(['name' => 'ventes.valider_reception', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'ventes.update', 'guard_name' => 'web']);

        $constate = Role::create(['name' => 'role_constate', 'guard_name' => 'web']);
        $constate->givePermissionTo('ventes.valider_reception');
        $autre = Role::create(['name' => 'role_autre', 'guard_name' => 'web']);
        $autre->givePermissionTo('ventes.update');

        $this->migration(self::MIGRATION_PERMISSION)->up();

        $this->assertTrue($constate->fresh()->hasPermissionTo('ventes.enregistrer_retour'));
        $this->assertFalse($autre->fresh()->hasPermissionTo('ventes.enregistrer_retour'));
        // Aucune permission retirée.
        $this->assertTrue($constate->fresh()->hasPermissionTo('ventes.valider_reception'));
        $this->assertTrue($autre->fresh()->hasPermissionTo('ventes.update'));
    }

    public function test_permission_rejouable_sans_doublon_ni_effet_de_bord(): void
    {
        Permission::firstOrCreate(['name' => 'ventes.valider_reception', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'role_constate', 'guard_name' => 'web']);
        $role->givePermissionTo('ventes.valider_reception');

        $migration = $this->migration(self::MIGRATION_PERMISSION);
        $migration->up();
        $migration->up();

        $this->assertSame(1, Permission::where('name', 'ventes.enregistrer_retour')->count());
        $this->assertSame(1, $role->fresh()->permissions()->where('name', 'ventes.enregistrer_retour')->count());
    }

    public function test_mappings_comptables_vente_retour_copies_depuis_vente_facturee(): void
    {
        $org = Organization::factory()->create();
        CompteMapping::where('organization_id', $org->id)->where('evenement', 'vente_retour')->delete();
        $this->assertSame(0, CompteMapping::where('organization_id', $org->id)->where('evenement', 'vente_retour')->count());

        $this->migration(self::MIGRATION_COMPTA)->up();

        foreach (['client', 'produit_vente'] as $role) {
            $source = CompteMapping::where('organization_id', $org->id)->where('evenement', 'vente_facturee')->where('role', $role)->firstOrFail();
            $copie = CompteMapping::where('organization_id', $org->id)->where('evenement', 'vente_retour')->where('role', $role)->firstOrFail();

            $this->assertSame($source->compte_comptable_id, $copie->compte_comptable_id);
            $this->assertSame($source->journal_comptable_id, $copie->journal_comptable_id);
        }
    }

    public function test_mappings_comptables_rejouables_et_sans_ecraser_une_configuration_existante(): void
    {
        $org = Organization::factory()->create();

        // Un administrateur a déjà ajusté le compte de produit_vente pour vente_retour : jamais écrasé.
        $personnalise = CompteMapping::where('organization_id', $org->id)->where('evenement', 'vente_retour')->where('role', 'produit_vente')->firstOrFail();
        $autreCompte = CompteMapping::where('organization_id', $org->id)->where('evenement', 'vente_facturee')->where('role', 'client')->firstOrFail()->compte_comptable_id;
        $personnalise->update(['compte_comptable_id' => $autreCompte]);

        $migration = $this->migration(self::MIGRATION_COMPTA);
        $migration->up();
        $migration->up();

        $this->assertSame(2, CompteMapping::where('organization_id', $org->id)->where('evenement', 'vente_retour')->count());
        $this->assertSame($autreCompte, $personnalise->fresh()->compte_comptable_id);
    }

    private function migration(string $fichier): Migration
    {
        return require database_path('migrations/'.$fichier);
    }
}
