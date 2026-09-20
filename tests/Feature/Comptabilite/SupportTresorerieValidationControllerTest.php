<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutSupportTresorerie;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\SupportTresorerieValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Écran Trésorerie > Supports : un support est créé en brouillon puis validé (permission dédiée
 * `tresorerie.valider_supports`, portée par agence, isolation par organisation). Seul un support
 * validé peut être actif ; un brouillon ne s'active jamais par une simple modification.
 */
class SupportTresorerieValidationControllerTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    private CompteComptable $compteCaisse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read', 'tresorerie.gerer_soldes_ouverture', 'tresorerie.valider_supports']);
        $this->site = $this->user->sites()->first();
        $this->compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
    }

    private function urlIndex(): string
    {
        return route('comptabilite.tresorerie.supports.index');
    }

    private function brouillon(?Site $site = null, string $libelle = 'Caisse en préparation'): CompteTresorerie
    {
        return CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => ($site ?? $this->site)->id,
            'compte_comptable_id' => $this->compteCaisse->id,
            'type' => 'caisse',
            'libelle' => $libelle,
            'actif' => false,
        ]);
    }

    private function siteSecondaire(): Site
    {
        return Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $superAdmin;
    }

    // ── Création : toujours en brouillon ─────────────────────────────────────

    public function test_store_cree_un_support_d_agence_en_brouillon(): void
    {
        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'site_id' => $this->site->id,
                'compte_comptable_id' => $this->compteCaisse->id,
                'type' => 'caisse',
                'libelle' => 'Caisse Matoto',
            ])
            ->assertRedirect($this->urlIndex())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'brouillon'));

        $support = CompteTresorerie::firstOrFail();
        $this->assertFalse($support->actif);
        $this->assertNull($support->valide_le);
        $this->assertNull($support->valide_par_id);
        $this->assertSame(StatutSupportTresorerie::BROUILLON, $support->statut());
    }

    public function test_store_cree_une_caisse_dediee_en_brouillon(): void
    {
        $agent = $this->creerAgent($this->site);

        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'nature' => 'dediee',
                'site_id' => $this->site->id,
                'agent_id' => $agent->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'brouillon'));

        $caisse = CompteTresorerie::dediees()->firstOrFail();
        $this->assertFalse($caisse->actif);
        $this->assertSame(StatutSupportTresorerie::BROUILLON, $caisse->statut());
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_valider_met_le_support_en_service_et_trace_le_validateur(): void
    {
        $support = $this->brouillon();

        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.supports.valider', $support))
            ->assertRedirect($this->urlIndex())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'validé'));

        $support->refresh();
        $this->assertTrue($support->actif);
        $this->assertSame(StatutSupportTresorerie::ACTIF, $support->statut());
        $this->assertSame($this->user->id, $support->valide_par_id);
        $this->assertNotNull($support->valide_le);
    }

    public function test_valider_refuse_sans_la_permission_de_validation(): void
    {
        $support = $this->brouillon();
        $this->user->revokePermissionTo('tresorerie.valider_supports');

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.supports.valider', $support))
            ->assertForbidden();

        $this->assertSame(StatutSupportTresorerie::BROUILLON, $support->fresh()->statut());
    }

    public function test_gerer_les_supports_ne_suffit_pas_pour_les_valider(): void
    {
        $support = $this->brouillon();
        $gestionnaire = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.gerer_soldes_ouverture']);

        $this->actingAs($gestionnaire)
            ->post(route('comptabilite.tresorerie.supports.valider', $support))
            ->assertForbidden();

        $this->assertSame(StatutSupportTresorerie::BROUILLON, $support->fresh()->statut());
    }

    public function test_valider_refuse_un_support_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Étranger', 'type' => 'agence', 'localisation' => 'Labé']);
        $chezLAutre = CompteTresorerie::create([
            'organization_id' => $autreOrg->id,
            'site_id' => $autreSite->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $autreOrg->id)->where('numero', '571000')->firstOrFail()->id,
            'type' => 'caisse',
            'libelle' => 'Caisse étrangère',
            'actif' => false,
        ]);

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.supports.valider', $chezLAutre))
            ->assertForbidden();

        $this->assertSame(StatutSupportTresorerie::BROUILLON, $chezLAutre->fresh()->statut());
    }

    public function test_un_valideur_non_admin_ne_valide_que_les_supports_de_ses_agences(): void
    {
        $autreSite = $this->siteSecondaire();
        $chezLui = $this->brouillon($this->site, 'Caisse de mon agence');
        $ailleurs = $this->brouillon($autreSite, 'Caisse de Kouria');
        $valideur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.valider_supports']);

        $this->actingAs($valideur)
            ->post(route('comptabilite.tresorerie.supports.valider', $ailleurs))
            ->assertForbidden();
        $this->assertSame(StatutSupportTresorerie::BROUILLON, $ailleurs->fresh()->statut());

        $this->actingAs($valideur)
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.supports.valider', $chezLui))
            ->assertSessionHasNoErrors();
        $this->assertSame(StatutSupportTresorerie::ACTIF, $chezLui->fresh()->statut());
        $this->assertSame($valideur->id, $chezLui->fresh()->valide_par_id);
    }

    public function test_valider_un_support_deja_valide_renvoie_une_erreur_meme_pour_le_super_admin(): void
    {
        $support = app(SupportTresorerieValidationService::class)->valider($this->brouillon(), $this->user);
        $validePar = $support->valide_par_id;

        $this->actingAs($this->superAdmin())
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.supports.valider', $support))
            ->assertSessionHasErrors('statut');

        $this->assertSame($validePar, $support->fresh()->valide_par_id);
    }

    public function test_valider_une_caisse_dediee_dont_l_agent_est_desactive_renvoie_une_erreur(): void
    {
        $agent = $this->creerAgent($this->site);
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.supports.store'), [
            'nature' => 'dediee',
            'site_id' => $this->site->id,
            'agent_id' => $agent->id,
        ]);
        $caisse = CompteTresorerie::dediees()->firstOrFail();
        $agent->update(['is_active' => false]);

        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->post(route('comptabilite.tresorerie.supports.valider', $caisse))
            ->assertSessionHasErrors('statut');

        $this->assertSame(StatutSupportTresorerie::BROUILLON, $caisse->fresh()->statut());
    }

    // ── Un brouillon ne s'active pas par une modification ────────────────────

    public function test_update_refuse_d_activer_un_support_d_agence_en_brouillon(): void
    {
        $support = $this->brouillon();

        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->put(route('comptabilite.tresorerie.supports.update', $support), [
                'libelle' => $support->libelle,
                'type' => 'caisse',
                'compte_comptable_id' => $this->compteCaisse->id,
                'actif' => true,
            ])
            ->assertSessionHasErrors('actif');

        $this->assertSame(StatutSupportTresorerie::BROUILLON, $support->fresh()->statut());
    }

    public function test_update_refuse_d_activer_une_caisse_dediee_en_brouillon(): void
    {
        $agent = $this->creerAgent($this->site);
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.supports.store'), [
            'nature' => 'dediee',
            'site_id' => $this->site->id,
            'agent_id' => $agent->id,
        ]);
        $caisse = CompteTresorerie::dediees()->firstOrFail();

        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->put(route('comptabilite.tresorerie.supports.update', $caisse), ['libelle' => $caisse->libelle, 'actif' => true])
            ->assertSessionHasErrors('actif');

        $this->assertSame(StatutSupportTresorerie::BROUILLON, $caisse->fresh()->statut());
    }

    public function test_update_permet_de_corriger_un_brouillon_sans_l_activer(): void
    {
        $support = $this->brouillon();

        $this->actingAs($this->user)
            ->from($this->urlIndex())
            ->put(route('comptabilite.tresorerie.supports.update', $support), [
                'libelle' => 'Caisse corrigée',
                'type' => 'caisse',
                'compte_comptable_id' => $this->compteCaisse->id,
                'actif' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Caisse corrigée', $support->fresh()->libelle);
        $this->assertSame(StatutSupportTresorerie::BROUILLON, $support->fresh()->statut());
    }

    // ── Écran : statut, droits de validation, filtre ─────────────────────────

    public function test_index_expose_le_statut_et_le_droit_de_valider_de_chaque_ligne(): void
    {
        $brouillon = $this->brouillon(null, 'A brouillon');
        $actif = app(SupportTresorerieValidationService::class)->valider($this->brouillon(null, 'B actif'), $this->user);
        $inactif = app(SupportTresorerieValidationService::class)->valider($this->brouillon(null, 'C inactif'), $this->user);
        $inactif->update(['actif' => false]);

        $this->actingAs($this->user)
            ->get($this->urlIndex())
            ->assertInertia(function (Assert $page) use ($brouillon, $actif, $inactif) {
                $comptes = collect($page->toArray()['props']['comptes'])->keyBy('id');

                $this->assertSame('brouillon', $comptes[$brouillon->id]['statut']);
                $this->assertSame('Brouillon', $comptes[$brouillon->id]['statut_label']);
                $this->assertTrue($comptes[$brouillon->id]['peut_valider']);
                $this->assertNull($comptes[$brouillon->id]['valide_par']);

                $this->assertSame('actif', $comptes[$actif->id]['statut']);
                $this->assertFalse($comptes[$actif->id]['peut_valider']);
                $this->assertSame($this->user->name, $comptes[$actif->id]['valide_par']);
                $this->assertNotNull($comptes[$actif->id]['valide_le']);

                $this->assertSame('inactif', $comptes[$inactif->id]['statut']);
                $this->assertSame('Inactif', $comptes[$inactif->id]['statut_label']);
                $this->assertFalse($comptes[$inactif->id]['peut_valider']);
            });
    }

    public function test_index_ne_propose_pas_de_valider_sans_la_permission_ni_hors_agence(): void
    {
        $chezLui = $this->brouillon($this->site, 'Chez lui');
        $ailleurs = $this->brouillon($this->siteSecondaire(), 'Ailleurs');
        $lecteur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read'], 'Lecteur', 'Simple');
        $valideur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.valider_supports'], 'Valideur', 'Agence');

        $this->actingAs($lecteur)->get($this->urlIndex())->assertInertia(function (Assert $page) use ($chezLui) {
            $comptes = collect($page->toArray()['props']['comptes'])->keyBy('id');
            $this->assertFalse($comptes[$chezLui->id]['peut_valider'], 'lecture seule : jamais d\'action de validation');
        });

        $this->actingAs($valideur)->get($this->urlIndex())->assertInertia(function (Assert $page) use ($chezLui, $ailleurs) {
            $comptes = collect($page->toArray()['props']['comptes'])->keyBy('id');
            $this->assertTrue($comptes[$chezLui->id]['peut_valider']);
            $this->assertArrayNotHasKey($ailleurs->id, $comptes->all(), 'un support d\'une autre agence n\'est même pas listé');
        });
    }

    public function test_le_super_admin_n_a_pas_d_action_valider_sur_un_support_deja_valide(): void
    {
        $actif = app(SupportTresorerieValidationService::class)->valider($this->brouillon(null, 'Déjà validée'), $this->user);
        $brouillon = $this->brouillon(null, 'À valider');

        $this->actingAs($this->superAdmin())
            ->get($this->urlIndex())
            ->assertInertia(function (Assert $page) use ($actif, $brouillon) {
                $comptes = collect($page->toArray()['props']['comptes'])->keyBy('id');
                $this->assertFalse($comptes[$actif->id]['peut_valider'], 'le Gate::before ne doit pas rouvrir une validation déjà faite');
                $this->assertTrue($comptes[$brouillon->id]['peut_valider']);
            });
    }

    public function test_le_filtre_statut_distingue_brouillon_actif_et_inactif(): void
    {
        $brouillon = $this->brouillon(null, 'A brouillon');
        $actif = app(SupportTresorerieValidationService::class)->valider($this->brouillon(null, 'B actif'), $this->user);
        $inactif = app(SupportTresorerieValidationService::class)->valider($this->brouillon(null, 'C inactif'), $this->user);
        $inactif->update(['actif' => false]);

        $ids = function (string $statut): array {
            $ids = [];
            $this->actingAs($this->user)
                ->get($this->urlIndex().'?statut='.$statut)
                ->assertInertia(function (Assert $page) use (&$ids) {
                    $ids = collect($page->toArray()['props']['comptes'])->pluck('id')->all();
                });

            return $ids;
        };

        $this->assertSame([$brouillon->id], $ids('brouillon'));
        $this->assertSame([$actif->id], $ids('actif'));
        $this->assertSame([$inactif->id], $ids('inactif'), 'un brouillon n\'est pas un support inactif');
        $this->assertCount(3, $ids(''));
    }
}
