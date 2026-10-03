<?php

namespace Tests\Feature\Migrations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR 0017 — rejoue la migration sur un état « avant » (colonne is_siege_principal, sites de type
 * siege) : le site central reste le même, les anciens sièges passent en `autre`, jamais vers un
 * type deviné.
 */
class DecouplerTypeSiteCentralTresorerieMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_02_300000_decoupler_type_site_et_central_tresorerie.php');
    }

    private function insererSite(string $orgId, string $nom, string $type, bool $principal): string
    {
        $id = (string) Str::ulid();
        DB::table('sites')->insert([
            'id' => $id, 'organization_id' => $orgId, 'nom' => $nom, 'code' => Str::upper(Str::random(6)),
            'type' => $type, 'statut' => 'active', 'is_siege_principal' => $principal,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_reclasse_les_sieges_en_autre_et_conserve_le_site_central(): void
    {
        $this->migration()->down();
        $this->assertTrue(Schema::hasColumn('sites', 'is_siege_principal'));

        $org = Organization::factory()->create();
        $siegePrincipal = $this->insererSite($org->id, 'Matoto', 'siege', true);
        $ancienSiege = $this->insererSite($org->id, 'Ancien siège', 'siege', false);
        $usine = $this->insererSite($org->id, 'CBA', 'usine', false);

        $this->migration()->up();

        $this->assertFalse(Schema::hasColumn('sites', 'is_siege_principal'));
        $sites = DB::table('sites')->get()->keyBy('id');
        $this->assertSame('autre', $sites[$siegePrincipal]->type);
        $this->assertSame('autre', $sites[$ancienSiege]->type);
        $this->assertSame('usine', $sites[$usine]->type);
        $this->assertTrue((bool) $sites[$siegePrincipal]->is_central_tresorerie);
        $this->assertFalse((bool) $sites[$ancienSiege]->is_central_tresorerie);
        $this->assertFalse((bool) $sites[$usine]->is_central_tresorerie);
    }

    public function test_reclasse_les_filtres_enregistres_sur_le_type_siege(): void
    {
        $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $insererVue = function (string $nom, array $filters) use ($user): string {
            $id = (string) Str::ulid();
            DB::table('saved_filters')->insert([
                'id' => $id, 'organization_id' => $user->organization_id, 'user_id' => $user->id,
                'scope' => 'commissions-sites', 'name' => $nom, 'visibility' => 'personal',
                'filters' => json_encode($filters), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        };
        $vueSiege = $insererVue('Sièges', ['site_type' => 'siege', 'filtre_statut' => 'a_payer']);
        $vueUsine = $insererVue('Usines', ['site_type' => 'usine']);

        $this->migration()->up();

        $filtres = fn (string $id) => json_decode(DB::table('saved_filters')->where('id', $id)->value('filters'), true);
        $this->assertSame(['site_type' => 'autre', 'filtre_statut' => 'a_payer'], $filtres($vueSiege));
        $this->assertSame(['site_type' => 'usine'], $filtres($vueUsine));
    }

    public function test_est_rejouable_sans_effet(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('sites', 'is_central_tresorerie'));
        $this->assertFalse(Schema::hasColumn('sites', 'is_siege_principal'));
    }
}
