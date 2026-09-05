<?php

namespace Tests\Feature\Settings;

use App\Models\DroitCreationDepense;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tests des paramètres dépenses : droits de validation par rôle uniquement —
 * la classification des types de dépense a déménagé dans le module Dépenses
 * (cf. Tests\Feature\DepenseTypeTest, routes /backoffice/depenses/types).
 *
 * - GET /settings/depenses : accessible avec parametres.update
 * - PUT /settings/depenses/droits : sauvegarde peut_valider, perimetre, sites
 */
class DepenseParametrageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        foreach (['admin_entreprise', 'manager', 'commerciale'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function adminWith(string $permission): User
    {
        Permission::findOrCreate($permission, 'web');

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permission);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    // ── Accès à la page ───────────────────────────────────────────────────────

    public function test_edit_accessible_avec_permission_parametres(): void
    {
        $this->actingAs($this->adminWith('parametres.update'))
            ->get(route('settings.depenses'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/DepenseParametrage')
                ->has('config')
                ->has('sites')
                // Les types de dépense ne sont plus rendus par cette page.
                ->missing('types')
                ->missing('categories')
            );
    }

    public function test_edit_forbidden_sans_permission(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)
            ->get(route('settings.depenses'))
            ->assertForbidden();
    }

    public function test_edit_config_contient_tous_les_roles(): void
    {
        $user = $this->adminWith('parametres.update');

        $this->actingAs($user)
            ->get(route('settings.depenses'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('config', fn (Assert $config) => $config
                    ->each(fn (Assert $row) => $row
                        ->has('role_name')
                        ->has('peut_valider')
                        ->has('perimetre')
                        ->has('sites')
                        ->has('plafond_validation')
                    )
                )
            );
    }

    public function test_edit_sites_contient_les_sites_de_lorg(): void
    {
        $user = $this->adminWith('parametres.update');

        $this->actingAs($user)
            ->get(route('settings.depenses'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sites', 1)
                ->where('sites.0.id', $this->site->id)
            );
    }

    // ── updateDroits ──────────────────────────────────────────────────────────

    public function test_update_droits_forbidden_sans_permission(): void
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), ['config' => []])
            ->assertForbidden();
    }

    public function test_update_droits_sauvegarde_peut_valider_true(): void
    {
        $user = $this->adminWith('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), [
                'config' => [
                    [
                        'role_name' => 'manager',
                        'peut_valider' => true,
                        'perimetre' => 'toutes_agences',
                        'sites' => [],
                        'plafond_validation' => 1000000,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('droit_creation_depenses', [
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'peut_valider' => true,
            'perimetre' => 'toutes_agences',
            'plafond_validation' => 1000000,
        ]);
    }

    public function test_update_droits_sauvegarde_peut_valider_false(): void
    {
        $user = $this->adminWith('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), [
                'config' => [
                    [
                        'role_name' => 'manager',
                        'peut_valider' => false,
                        'perimetre' => 'toutes_agences',
                        'sites' => [],
                        'plafond_validation' => null,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('droit_creation_depenses', [
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'peut_valider' => false,
        ]);
    }

    // ── plafond_validation ────────────────────────────────────────────────────

    public function test_update_droits_rejette_peut_valider_true_sans_plafond(): void
    {
        $user = $this->adminWith('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), [
                'config' => [
                    [
                        'role_name' => 'manager',
                        'peut_valider' => true,
                        'perimetre' => 'toutes_agences',
                        'sites' => [],
                        'plafond_validation' => null,
                    ],
                ],
            ])
            ->assertSessionHasErrors(['config.0.plafond_validation']);

        $this->assertDatabaseMissing('droit_creation_depenses', [
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
        ]);
    }

    public function test_update_droits_rejette_plafond_negatif(): void
    {
        $user = $this->adminWith('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), [
                'config' => [
                    [
                        'role_name' => 'manager',
                        'peut_valider' => true,
                        'perimetre' => 'toutes_agences',
                        'sites' => [],
                        'plafond_validation' => -1,
                    ],
                ],
            ])
            ->assertSessionHasErrors(['config.0.plafond_validation']);
    }

    public function test_update_droits_efface_le_plafond_quand_peut_valider_desactive(): void
    {
        $user = $this->adminWith('parametres.update');

        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => true,
            'plafond_validation' => 500000,
        ]);

        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), [
                'config' => [
                    [
                        'role_name' => 'manager',
                        'peut_valider' => false,
                        'perimetre' => 'toutes_agences',
                        'sites' => [],
                        // Un plafond résiduel côté client est ignoré : le
                        // serveur force NULL dès que peut_valider est false.
                        'plafond_validation' => 500000,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('droit_creation_depenses', [
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'peut_valider' => false,
            'plafond_validation' => null,
        ]);
    }

    // ── Admin Entreprise — plafond obligatoire malgré peut_valider forcé ────────
    // Correction 04/09/2026 : Admin Entreprise garde son accès automatique
    // (RBAC/agences) mais est désormais soumis au plafond de montant comme
    // tout autre rôle. Sa case "Peut valider" reste verrouillée à true côté
    // UI ; le backend force donc peut_valider=true pour sa ligne avant
    // validation, indépendamment de ce que le client envoie, pour que le
    // plafond lui soit imposé et que la ligne reste utilisable par
    // droitValidationPour().

    public function test_update_droits_sauvegarde_le_plafond_admin_entreprise(): void
    {
        $user = $this->adminWith('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), [
                'config' => [
                    [
                        'role_name' => 'admin_entreprise',
                        'peut_valider' => false, // ignoré, forcé à true côté serveur
                        'perimetre' => 'toutes_agences',
                        'sites' => [],
                        'plafond_validation' => 2000000,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('droit_creation_depenses', [
            'organization_id' => $this->org->id,
            'role_name' => 'admin_entreprise',
            'peut_valider' => true,
            'plafond_validation' => 2000000,
        ]);
    }

    public function test_update_droits_rejette_admin_entreprise_sans_plafond(): void
    {
        $user = $this->adminWith('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), [
                'config' => [
                    [
                        'role_name' => 'admin_entreprise',
                        'peut_valider' => false,
                        'perimetre' => 'toutes_agences',
                        'sites' => [],
                        'plafond_validation' => null,
                    ],
                ],
            ])
            ->assertSessionHasErrors(['config.0.plafond_validation']);

        $this->assertDatabaseMissing('droit_creation_depenses', [
            'organization_id' => $this->org->id,
            'role_name' => 'admin_entreprise',
        ]);
    }

    public function test_update_droits_modifie_un_plafond_existant(): void
    {
        $user = $this->adminWith('parametres.update');

        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => true,
            'plafond_validation' => 500000,
        ]);

        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), [
                'config' => [
                    [
                        'role_name' => 'manager',
                        'peut_valider' => true,
                        'perimetre' => 'toutes_agences',
                        'sites' => [],
                        'plafond_validation' => 750000,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('droit_creation_depenses', [
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'plafond_validation' => 750000,
        ]);
    }

    public function test_update_droits_sauvegarde_perimetre_son_agence(): void
    {
        $user = $this->adminWith('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), [
                'config' => [
                    [
                        'role_name' => 'manager',
                        'peut_valider' => true,
                        'perimetre' => 'son_agence',
                        'sites' => [],
                        'plafond_validation' => 500000,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('droit_creation_depenses', [
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'perimetre' => 'son_agence',
        ]);
    }

    public function test_update_droits_sauvegarde_perimetre_agences_selectionnees_avec_sites(): void
    {
        $user = $this->adminWith('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), [
                'config' => [
                    [
                        'role_name' => 'manager',
                        'peut_valider' => true,
                        'perimetre' => 'agences_selectionnees',
                        'sites' => [$this->site->id],
                        'plafond_validation' => 500000,
                    ],
                ],
            ])
            ->assertRedirect();

        $droit = DroitCreationDepense::where('organization_id', $this->org->id)
            ->where('role_name', 'manager')
            ->first();

        $this->assertNotNull($droit);
        $this->assertEquals('agences_selectionnees', $droit->perimetre);
        $this->assertContains($this->site->id, $droit->sites);
    }

    public function test_update_droits_sites_ignores_si_perimetre_pas_agences_selectionnees(): void
    {
        $user = $this->adminWith('parametres.update');

        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), [
                'config' => [
                    [
                        'role_name' => 'manager',
                        'peut_valider' => true,
                        'perimetre' => 'toutes_agences',
                        'sites' => [$this->site->id], // ignoré car périmètre pas agences_selectionnees
                        'plafond_validation' => 500000,
                    ],
                ],
            ])
            ->assertRedirect();

        $droit = DroitCreationDepense::where('organization_id', $this->org->id)
            ->where('role_name', 'manager')
            ->first();

        $this->assertNull($droit->sites);
    }

    public function test_update_droits_met_a_jour_ligne_existante(): void
    {
        $user = $this->adminWith('parametres.update');

        // Créer une ligne existante
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => false,
        ]);

        // Mettre à jour
        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), [
                'config' => [
                    [
                        'role_name' => 'manager',
                        'peut_valider' => true,
                        'perimetre' => 'son_agence',
                        'sites' => [],
                        'plafond_validation' => 500000,
                    ],
                ],
            ])
            ->assertRedirect();

        // Doit y avoir exactement 1 ligne (updateOrCreate)
        $this->assertEquals(1, DroitCreationDepense::where([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
        ])->count());

        $this->assertDatabaseHas('droit_creation_depenses', [
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'peut_valider' => true,
            'perimetre' => 'son_agence',
        ]);
    }

    public function test_update_droits_rejette_site_autre_org(): void
    {
        $user = $this->adminWith('parametres.update');

        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create([
            'organization_id' => $autreOrg->id,
            'nom' => 'Site Externe',
            'type' => 'depot',
            'localisation' => 'Dakar',
        ]);

        $this->actingAs($user)
            ->put(route('settings.depenses.droits'), [
                'config' => [
                    [
                        'role_name' => 'manager',
                        'peut_valider' => true,
                        'perimetre' => 'agences_selectionnees',
                        'sites' => [$autreSite->id],
                        'plafond_validation' => 500000,
                    ],
                ],
            ])
            ->assertSessionHasErrors();
    }
}
