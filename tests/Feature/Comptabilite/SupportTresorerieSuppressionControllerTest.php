<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\StatutMouvementFonds;
use App\Models\AuditLog;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\SupportTresorerieValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Suppression d'un support de trésorerie resté en brouillon (ADR 0028) : seul un brouillon jamais
 * validé se supprime, avec le sous-compte propre d'un support dédié s'il n'a jamais servi ; un
 * support validé (actif ou désactivé) se désactive seulement. Permission dédiée
 * `tresorerie.supprimer_supports`, portée par agence, isolation par organisation, trace d'audit.
 */
class SupportTresorerieSuppressionControllerTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    private CompteComptable $compteCaisse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read', 'tresorerie.gerer_soldes_ouverture', 'tresorerie.valider_supports', 'tresorerie.supprimer_supports']);
        $this->site = $this->user->sites()->first();
        $this->compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
    }

    private function urlIndex(): string
    {
        return route('comptabilite.tresorerie.supports.index');
    }

    private function supprimer(CompteTresorerie $support, ?User $par = null, array $donnees = [])
    {
        return $this->actingAs($par ?? $this->user)
            ->from($this->urlIndex())
            ->delete(route('comptabilite.tresorerie.supports.destroy', $support), $donnees);
    }

    private function brouillonAgence(?Site $site = null, string $libelle = 'Caisse en préparation'): CompteTresorerie
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

    // ── Brouillon : suppression définitive ───────────────────────────────────

    public function test_un_brouillon_d_agence_est_supprime_sans_toucher_au_compte_du_plan(): void
    {
        $brouillon = $this->brouillonAgence();

        $this->supprimer($brouillon, donnees: ['motif' => 'Créé par erreur'])
            ->assertRedirect($this->urlIndex())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertNull(CompteTresorerie::find($brouillon->id));
        $this->assertNotNull(CompteComptable::find($this->compteCaisse->id), 'le compte 571000 partagé reste au plan');
    }

    public function test_la_suppression_est_tracee_avec_l_auteur_la_fiche_et_le_motif(): void
    {
        $brouillon = $this->brouillonAgence(libelle: 'Caisse doublon');

        $this->supprimer($brouillon, donnees: ['motif' => 'Doublon de la caisse principale'])->assertSessionHasNoErrors();

        $trace = AuditLog::where('auditable_id', $brouillon->id)->where('event_code', AuditEvent::DELETED->value)->sole();
        $this->assertSame($this->user->id, $trace->actor_id);
        $this->assertSame($this->org->id, $trace->organization_id);
        $this->assertSame('Caisse doublon', $trace->old_values['libelle']);
        $this->assertSame('Site Principal', $trace->old_values['site']);
        $this->assertSame('571000', $trace->old_values['compte_numero']);
        $this->assertSame('Doublon de la caisse principale', $trace->meta['motif']);
    }

    public function test_le_motif_est_facultatif(): void
    {
        $brouillon = $this->brouillonAgence();

        $this->supprimer($brouillon)->assertSessionHasNoErrors();

        $this->assertNull(CompteTresorerie::find($brouillon->id));
        $this->assertNull(AuditLog::where('auditable_id', $brouillon->id)->sole()->meta);
    }

    public function test_une_caisse_dediee_en_brouillon_est_supprimee_avec_son_sous_compte(): void
    {
        $agent = $this->creerAgent($this->site);
        $brouillon = app(CaisseAgentService::class)->creer($this->org->id, $this->site->id, $agent->id);
        $sousCompteId = $brouillon->compte_comptable_id;

        $this->supprimer($brouillon)->assertSessionHasNoErrors();

        $this->assertNull(CompteTresorerie::find($brouillon->id));
        $this->assertNull(CompteComptable::find($sousCompteId), 'le sous-compte 571xxx jamais utilisé disparaît avec la caisse');
        $this->assertSame($agent->name, AuditLog::where('auditable_id', $brouillon->id)->sole()->old_values['agent']);
    }

    public function test_un_compte_mobile_money_d_agent_en_brouillon_est_supprime_avec_son_sous_compte(): void
    {
        $agent = $this->creerAgent($this->site);
        $brouillon = app(CaisseAgentService::class)->creerCompteMobileMoney($this->org->id, $this->site->id, $agent->id, 'orange_money', '620000001');
        $sousCompteId = $brouillon->compte_comptable_id;

        $this->supprimer($brouillon)->assertSessionHasNoErrors();

        $this->assertNull(CompteTresorerie::find($brouillon->id));
        $this->assertNull(CompteComptable::find($sousCompteId));
    }

    public function test_apres_suppression_du_brouillon_une_nouvelle_caisse_peut_etre_creee_pour_l_agent(): void
    {
        $agent = $this->creerAgent($this->site);
        $service = app(CaisseAgentService::class);
        $brouillon = $service->creer($this->org->id, $this->site->id, $agent->id);
        $this->assertErreurValidationSur('agent_id', fn () => $service->creer($this->org->id, $this->site->id, $agent->id));

        $this->supprimer($brouillon)->assertSessionHasNoErrors();

        $this->assertNotNull($service->creer($this->org->id, $this->site->id, $agent->id));
    }

    public function test_un_doublon_en_brouillon_a_cote_d_une_caisse_active_se_supprime(): void
    {
        // Cas réel (Matoto) : caisse active + brouillons antérieurs qui ne pourront jamais être validés.
        $agent = $this->creerAgent($this->site);
        $active = $this->creerCaisseActive($this->site->id, $agent->id);
        $doublon = CompteTresorerie::create([
            ...$active->only(['organization_id', 'site_id', 'agent_id', 'type', 'moyen_paiement_defaut']),
            'compte_comptable_id' => CompteComptable::create([
                'organization_id' => $this->org->id, 'numero' => '571099', 'libelle' => 'Doublon', 'parent_id' => $this->compteCaisse->id, 'actif' => true,
            ])->id,
            'libelle' => 'Caisse Moussa Sidibé (2)',
            'actif' => false,
        ]);

        $this->supprimer($doublon)->assertSessionHasNoErrors();

        $this->assertNull(CompteTresorerie::find($doublon->id));
        $this->assertTrue($active->fresh()->actif, 'la caisse active de l\'agent est intacte');
        $this->assertNotNull(CompteComptable::find($active->compte_comptable_id));
    }

    // ── Support validé : jamais supprimé ─────────────────────────────────────

    public function test_un_support_actif_ou_desactive_n_est_jamais_supprime(): void
    {
        $actif = app(SupportTresorerieValidationService::class)->valider($this->brouillonAgence(libelle: 'Caisse active'), $this->user);
        $inactif = app(SupportTresorerieValidationService::class)->valider($this->brouillonAgence(libelle: 'Caisse fermée'), $this->user);
        $inactif->update(['actif' => false]);

        foreach ([$actif, $inactif] as $support) {
            $this->supprimer($support)->assertSessionHasErrors('statut');
            $this->assertNotNull(CompteTresorerie::find($support->id));
        }
        $this->assertSame(0, AuditLog::where('event_code', AuditEvent::DELETED->value)->count());
    }

    public function test_le_super_admin_ne_supprime_pas_non_plus_un_support_valide(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        $agent = $this->creerAgent($this->site);
        $caisse = $this->creerCaisseActive($this->site->id, $agent->id);

        $this->supprimer($caisse, $superAdmin)->assertSessionHasErrors('statut');

        $this->assertNotNull(CompteTresorerie::find($caisse->id));
        $this->assertNotNull(CompteComptable::find($caisse->compte_comptable_id));
    }

    public function test_un_brouillon_reference_par_une_operation_n_est_pas_supprime(): void
    {
        $brouillon = $this->brouillonAgence();
        $autre = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        MouvementFonds::create([
            'organization_id' => $this->org->id,
            'nature' => 'inter_sites',
            'site_origine_id' => $this->site->id,
            'site_destination_id' => $autre->id,
            'compte_tresorerie_origine_id' => $brouillon->id,
            'montant' => 100_000,
            'statut' => StatutMouvementFonds::BROUILLON->value,
        ]);

        $this->supprimer($brouillon)->assertSessionHasErrors('statut');

        $this->assertNotNull(CompteTresorerie::find($brouillon->id));
    }

    // ── Autorisation ─────────────────────────────────────────────────────────

    public function test_sans_la_permission_dediee_la_suppression_est_refusee(): void
    {
        $brouillon = $this->brouillonAgence();
        $gestionnaire = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.gerer_soldes_ouverture', 'tresorerie.valider_supports']);

        $this->supprimer($brouillon, $gestionnaire)->assertForbidden();

        $this->assertNotNull(CompteTresorerie::find($brouillon->id));
    }

    public function test_la_permission_suffit_dans_son_agence_mais_pas_dans_une_autre(): void
    {
        $kouria = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $responsable = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.supprimer_supports']);
        $ici = $this->brouillonAgence();
        $ailleurs = $this->brouillonAgence($kouria, 'Caisse de Kouria');

        $this->supprimer($ailleurs, $responsable)->assertForbidden();
        $this->supprimer($ici, $responsable)->assertSessionHasNoErrors();

        $this->assertNull(CompteTresorerie::find($ici->id));
        $this->assertNotNull(CompteTresorerie::find($ailleurs->id));
    }

    public function test_un_brouillon_d_une_autre_organisation_est_inaccessible(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Ailleurs', 'type' => 'agence', 'localisation' => 'Kindia']);
        $etranger = CompteTresorerie::create([
            'organization_id' => $autreOrg->id,
            'site_id' => $autreSite->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $autreOrg->id)->where('numero', '571000')->firstOrFail()->id,
            'type' => 'caisse',
            'libelle' => 'Caisse étrangère',
            'actif' => false,
        ]);

        $this->supprimer($etranger)->assertForbidden();

        $this->assertNotNull(CompteTresorerie::find($etranger->id));
    }

    // ── Écran ────────────────────────────────────────────────────────────────

    public function test_l_index_indique_les_supports_supprimables(): void
    {
        $brouillon = $this->brouillonAgence(libelle: 'Brouillon');
        $actif = app(SupportTresorerieValidationService::class)->valider($this->brouillonAgence(libelle: 'Active'), $this->user);

        $this->actingAs($this->user)->get($this->urlIndex())
            ->assertInertia(fn (Assert $page) => $page
                ->where('comptes', fn ($comptes) => collect($comptes)->firstWhere('id', $brouillon->id)['peut_supprimer'] === true
                    && collect($comptes)->firstWhere('id', $actif->id)['peut_supprimer'] === false)
            );

        $lecteur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read', 'tresorerie.gerer_soldes_ouverture']);
        $this->actingAs($lecteur)->get($this->urlIndex())
            ->assertInertia(fn (Assert $page) => $page
                ->where('comptes', fn ($comptes) => collect($comptes)->firstWhere('id', $brouillon->id)['peut_supprimer'] === false)
            );
    }
}
