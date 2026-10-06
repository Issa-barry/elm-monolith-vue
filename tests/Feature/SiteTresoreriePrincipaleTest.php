<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Support\Sites\SiteFormSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * ADR 0017 — interrupteur « Trésorerie principale » du formulaire Site : une seule par
 * organisation, transférée en l'activant sur un autre site, jamais retirée par décochage, et
 * réservée à `tresorerie.designer_principale`.
 */
class SiteTresoreriePrincipaleTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $principale;

    private Site $labe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['sites.read', 'sites.create', 'sites.update', 'tresorerie.designer_principale']);
        $this->principale = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'agence', 'is_central_tresorerie' => true]);
        $this->labe = Site::create(['organization_id' => $this->org->id, 'nom' => 'Labé', 'type' => 'agence']);
    }

    private function payload(Site $site, array $overrides = []): array
    {
        return [
            'nom' => $site->nom, 'code' => $site->code, 'type' => $site->type->value,
            ...$overrides,
        ];
    }

    public function test_activer_sur_un_autre_site_transfere_la_tresorerie_principale(): void
    {
        $this->actingAs($this->user)
            ->put(route('sites.update', $this->labe), $this->payload($this->labe, ['is_central_tresorerie' => true]))
            ->assertRedirect(route('sites.index'));

        $this->assertTrue($this->labe->fresh()->isCentralTresorerie());
        $this->assertFalse($this->principale->fresh()->isCentralTresorerie());
    }

    public function test_decocher_la_tresorerie_principale_est_refuse(): void
    {
        $this->actingAs($this->user)
            ->put(route('sites.update', $this->principale), $this->payload($this->principale, ['is_central_tresorerie' => false]))
            ->assertSessionHasErrors(['is_central_tresorerie' => SiteFormSupport::MESSAGE_RETRAIT_TRESORERIE_PRINCIPALE]);

        $this->assertTrue($this->principale->fresh()->isCentralTresorerie());
    }

    public function test_changer_de_type_conserve_la_tresorerie_principale(): void
    {
        $this->actingAs($this->user)
            ->put(route('sites.update', $this->principale), $this->payload($this->principale, ['type' => 'depot', 'is_central_tresorerie' => true]))
            ->assertRedirect(route('sites.index'));

        $site = $this->principale->fresh();
        $this->assertSame('depot', $site->type->value);
        $this->assertTrue($site->isCentralTresorerie());
    }

    public function test_creer_un_site_comme_tresorerie_principale_la_transfere(): void
    {
        $this->actingAs($this->user)
            ->post(route('sites.store'), ['nom' => 'Kindia', 'type' => 'agence', 'is_central_tresorerie' => true])
            ->assertRedirect(route('sites.index'));

        $kindia = Site::where('organization_id', $this->org->id)->where('nom', 'Kindia')->firstOrFail();
        $this->assertTrue($kindia->isCentralTresorerie());
        $this->assertFalse($this->principale->fresh()->isCentralTresorerie());
    }

    public function test_sans_permission_le_changement_est_refuse(): void
    {
        $manager = $this->makeUserWithPermissions($this->org, ['sites.read', 'sites.create', 'sites.update']);
        $manager->sites()->attach($this->labe->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($manager)
            ->put(route('sites.update', $this->labe), $this->payload($this->labe, ['is_central_tresorerie' => true]))
            ->assertForbidden();
        $this->actingAs($manager)
            ->post(route('sites.store'), ['nom' => 'Kindia', 'type' => 'agence', 'is_central_tresorerie' => true])
            ->assertForbidden();

        $this->assertTrue($this->principale->fresh()->isCentralTresorerie());
        $this->assertFalse($this->labe->fresh()->isCentralTresorerie());
    }

    public function test_sans_permission_la_valeur_inchangee_n_empeche_pas_la_modification(): void
    {
        $manager = $this->makeUserWithPermissions($this->org, ['sites.read', 'sites.update']);
        $manager->sites()->attach($this->principale->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($manager)
            ->put(route('sites.update', $this->principale), $this->payload($this->principale, ['nom' => 'Matoto Centre', 'is_central_tresorerie' => true]))
            ->assertRedirect(route('sites.index'));

        $site = $this->principale->fresh();
        $this->assertSame('Matoto Centre', $site->nom);
        $this->assertTrue($site->isCentralTresorerie());
    }

    public function test_le_formulaire_expose_la_tresorerie_principale_actuelle_de_l_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Kaloum', 'type' => 'agence', 'is_central_tresorerie' => true]);

        $attendu = ['id' => $this->principale->id, 'nom' => 'Matoto'];
        $this->actingAs($this->user)->get(route('sites.edit', $this->labe))
            ->assertInertia(fn (Assert $page) => $page->where('tresorerie_principale', $attendu)->where('site.is_central_tresorerie', false));
        $this->actingAs($this->user)->get(route('sites.create'))
            ->assertInertia(fn (Assert $page) => $page->where('tresorerie_principale', $attendu));
    }
}
