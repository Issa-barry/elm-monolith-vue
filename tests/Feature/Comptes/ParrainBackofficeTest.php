<?php

namespace Tests\Feature\Comptes;

use App\Enums\AuditEvent;
use App\Enums\ModePaiement;
use App\Enums\StatutFichePaiement;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\AuditLog;
use App\Models\CommissionEnveloppePart;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\Parrain;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Liste et fiche des parrains au back-office, rattachement et détachement manuels d'un compte
 * (ADR 0050, lot 2).
 */
class ParrainBackofficeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initOrgAndUser(['parrains.read', 'rattachements-comptes.gerer']);
    }

    private function donnerToutesLesAgences(User $user): void
    {
        Permission::firstOrCreate(['name' => User::PERMISSION_LECTURE_TOUTES_AGENCES, 'guard_name' => 'web']);
        $user->givePermissionTo(User::PERMISSION_LECTURE_TOUTES_AGENCES);
    }

    private function parrain(array $attributs = []): Parrain
    {
        return Parrain::factory()->create(['organization_id' => $this->org->id, ...$attributs]);
    }

    private function compte(string $telephone = '+224620000321', array $attributs = []): User
    {
        $compte = User::factory()->create(['organization_id' => null, 'telephone' => $telephone, ...$attributs]);
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $compte->assignRole('client');

        return $compte;
    }

    /** Membre du personnel d'une seule agence, sans vision toutes agences ni droit implicite. */
    private function utilisateurAvec(array $permissions, ?Site $site = null): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $user->sites()->attach($site?->id ?? $this->user->sites()->value('sites.id'), ['role' => 'employe', 'is_default' => true]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function fiche(Parrain $parrain, Site $site, array $attributs = []): PaiementFiche
    {
        $periode = PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'type' => TypePeriodePaiement::PARRAIN->value,
            'reference' => 'PAY-'.fake()->unique()->numerify('######').'-P1-PAR',
            'date_debut' => '2026-10-01',
            'date_fin' => '2026-10-15',
            'statut' => StatutPeriodePaiement::VALIDEE->value,
        ]);

        return PaiementFiche::create([
            'organization_id' => $this->org->id,
            'periode_id' => $periode->id,
            'reference' => 'FP-'.fake()->unique()->numerify('######'),
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PARRAIN,
            'beneficiaire_id' => $parrain->id,
            'beneficiaire_nom' => $parrain->nom_complet,
            'site_id' => $site->id,
            'montant_brut' => 100000,
            'total_deductions' => 0,
            'montant_net' => 100000,
            'montant_paye' => 0,
            'statut' => StatutFichePaiement::A_PAYER->value,
            ...$attributs,
        ]);
    }

    // ── Accès ───────────────────────────────────────────────────────────────

    public function test_la_liste_exige_le_droit_de_lecture(): void
    {
        $this->actingAs($this->utilisateurAvec([]))->get(route('parrains.index'))->assertForbidden();
    }

    public function test_la_liste_et_la_fiche_affichent_le_parrain_et_son_compte(): void
    {
        $compte = $this->compte();
        $parrain = $this->parrain(['nom_complet' => 'Awa Bah']);
        $parrain->forceFill(['user_id' => $compte->id])->save();
        Vehicule::factory()->create(['organization_id' => $this->org->id, 'parrain_id' => $parrain->id]);

        $this->actingAs($this->user)->get(route('parrains.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Parrains/Index')
                ->has('parrains', 1)
                ->where('parrains.0.nom_complet', 'Awa Bah')
                ->where('parrains.0.vehicules_count', 1));

        $this->actingAs($this->user)->get(route('parrains.show', $parrain))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Parrains/Show')
                ->where('parrain.id', $parrain->id)
                ->where('compte.id', $compte->id)
                ->where('compte.actif', true)
                ->has('vehicules', 1));
    }

    public function test_un_parrain_d_une_autre_organisation_reste_invisible(): void
    {
        $autre = Parrain::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($this->user)->get(route('parrains.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('parrains', 0));

        $this->actingAs($this->user)->get(route('parrains.show', $autre))->assertForbidden();
        $this->actingAs($this->user)->getJson(route('parrains.compte.candidat', $autre).'?telephone=%2B224620000321')->assertForbidden();
        $this->actingAs($this->user)->delete(route('parrains.compte.destroy', $autre))->assertForbidden();
    }

    public function test_hors_vision_toutes_agences_seuls_les_parrains_de_ses_agences_sont_visibles(): void
    {
        $siteA = Site::factory()->create(['organization_id' => $this->org->id]);
        $siteB = Site::factory()->create(['organization_id' => $this->org->id]);

        $user = $this->utilisateurAvec(['parrains.read'], $siteA);

        $parrainVehicule = $this->parrain();
        Vehicule::factory()->create(['organization_id' => $this->org->id, 'site_id' => $siteA->id, 'parrain_id' => $parrainVehicule->id]);
        $parrainFiche = $this->parrain();
        $this->fiche($parrainFiche, $siteA);
        $autreAgence = $this->parrain();
        Vehicule::factory()->create(['organization_id' => $this->org->id, 'site_id' => $siteB->id, 'parrain_id' => $autreAgence->id]);

        $this->actingAs($user)->get(route('parrains.index'))
            ->assertInertia(function (AssertableInertia $page) use ($parrainVehicule, $parrainFiche) {
                $ids = collect($page->toArray()['props']['parrains'])->pluck('id')->all();
                $this->assertEqualsCanonicalizing([$parrainVehicule->id, $parrainFiche->id], $ids);
            });

        $this->actingAs($user)->get(route('parrains.show', $autreAgence))->assertForbidden();
    }

    // ── Filtres ─────────────────────────────────────────────────────────────

    public function test_les_filtres_compte_agence_et_recherche_s_appliquent(): void
    {
        $site = Site::factory()->create(['organization_id' => $this->org->id]);
        $rattache = $this->parrain(['nom_complet' => 'Awa Bah']);
        $rattache->forceFill(['user_id' => $this->compte()->id])->save();
        Vehicule::factory()->create(['organization_id' => $this->org->id, 'site_id' => $site->id, 'parrain_id' => $rattache->id]);
        $libre = $this->parrain(['nom_complet' => 'Binta Sow']);

        $attendre = fn (array $parametres, string $id) => $this->actingAs($this->user)
            ->get(route('parrains.index', $parametres))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('parrains', 1)->where('parrains.0.id', $id));

        $attendre(['compte' => 'rattache'], $rattache->id);
        $attendre(['compte' => 'non_rattache'], $libre->id);
        $attendre(['site_ids' => [$site->id]], $rattache->id);
        $attendre(['search' => 'Binta'], $libre->id);
    }

    // ── Commissions ─────────────────────────────────────────────────────────

    public function test_les_commissions_exigent_le_droit_comptabilite(): void
    {
        $lecteur = $this->utilisateurAvec(['parrains.read']);
        $this->donnerToutesLesAgences($lecteur);
        $parrain = $this->parrain();

        $this->actingAs($lecteur)->get(route('parrains.show', $parrain))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('commissions', null));

        $this->actingAs($lecteur)->get(route('parrains.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('peut_voir_commissions', false));
    }

    public function test_les_montants_viennent_des_fiches_sans_rien_modifier(): void
    {
        $user = $this->utilisateurAvec(['parrains.read', 'comptabilite.read']);
        $this->donnerToutesLesAgences($user);
        $site = Site::factory()->create(['organization_id' => $this->org->id]);
        $parrain = $this->parrain();

        $fiche = $this->fiche($parrain, $site, [
            'montant_paye' => 30000,
            'statut' => StatutFichePaiement::PARTIELLEMENT_PAYE->value,
        ]);
        $this->fiche($parrain, $site, [
            'montant_net' => 50000,
            'montant_brut' => 50000,
            'montant_paye' => 50000,
            'statut' => StatutFichePaiement::PAYE->value,
        ]);
        PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'montant' => 30000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-10-20',
        ]);
        $fiche->refresh();
        $avant = $fiche->only(['montant_net', 'montant_paye', 'statut']);

        $this->actingAs($user)->get(route('parrains.show', $parrain))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commissions.total_net', 150000)
                ->where('commissions.total_paye', 80000)
                ->where('commissions.total_restant', 70000)
                ->has('commissions.fiches', 2)
                ->has('commissions.paiements', 1)
                ->where('commissions.paiements.0.mode_paiement', ModePaiement::ESPECES->label()));

        $this->actingAs($user)->get(route('parrains.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('parrains.0.reste_a_payer', 70000));

        $this->assertSame($avant, $fiche->fresh()->only(['montant_net', 'montant_paye', 'statut']));
    }

    public function test_les_montants_se_limitent_aux_agences_de_l_utilisateur(): void
    {
        $siteA = Site::factory()->create(['organization_id' => $this->org->id]);
        $siteB = Site::factory()->create(['organization_id' => $this->org->id]);
        $user = $this->utilisateurAvec(['parrains.read', 'comptabilite.read'], $siteA);
        $parrain = $this->parrain();
        $this->fiche($parrain, $siteA);
        $this->fiche($parrain, $siteB);

        $this->actingAs($user)->get(route('parrains.show', $parrain))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commissions.total_net', 100000)
                ->where('perimetre_limite', true));
    }

    // ── Compte proposé ──────────────────────────────────────────────────────

    public function test_la_recherche_propose_un_compte_sans_jamais_le_relier(): void
    {
        $parrain = $this->parrain();
        $compte = $this->compte();

        $this->actingAs($this->user)
            ->getJson(route('parrains.compte.candidat', $parrain).'?telephone=%2B224620000321')
            ->assertOk()
            ->assertJsonPath('compte.id', $compte->id)
            ->assertJsonPath('compte.motif_refus', null);

        $this->assertNull($parrain->fresh()->user_id);
        $this->assertSame(0, AuditLog::where('event_code', AuditEvent::COMPTE_RATTACHE->value)->count());
    }

    public function test_un_numero_sans_compte_ne_propose_rien(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('parrains.compte.candidat', $this->parrain()).'?telephone=%2B224620000999')
            ->assertOk()
            ->assertJsonPath('compte', null);
    }

    public function test_le_compte_d_une_autre_organisation_ne_montre_que_le_motif(): void
    {
        $this->compte(attributs: [
            'organization_id' => Organization::factory()->create()->id,
            'email' => 'secret@example.test',
        ]);

        $this->actingAs($this->user)
            ->getJson(route('parrains.compte.candidat', $this->parrain()).'?telephone=%2B224620000321')
            ->assertOk()
            ->assertJsonPath('compte.id', null)
            ->assertJsonPath('compte.nom', null)
            ->assertJsonPath('compte.telephone', null)
            ->assertJsonPath('compte.email', null)
            ->assertJsonPath('compte.roles', [])
            ->assertJsonPath('compte.motif_refus', 'Ce compte appartient à une autre organisation.');
    }

    // ── Rattachement et détachement ─────────────────────────────────────────

    public function test_le_rattachement_exige_la_confirmation_du_responsable(): void
    {
        $parrain = $this->parrain();
        $compte = $this->compte();

        $this->actingAs($this->user)
            ->post(route('parrains.compte.store', $parrain), ['compte_id' => $compte->id])
            ->assertSessionHasErrors('identite_verifiee');

        $this->assertNull($parrain->fresh()->user_id);
    }

    public function test_le_rattachement_relie_le_compte_et_laisse_une_trace(): void
    {
        $parrain = $this->parrain();
        $compte = $this->compte();

        $this->actingAs($this->user)
            ->post(route('parrains.compte.store', $parrain), ['compte_id' => $compte->id, 'identite_verifiee' => true])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($compte->id, $parrain->fresh()->user_id);

        $log = AuditLog::where('event_code', AuditEvent::COMPTE_RATTACHE->value)->sole();
        $this->assertSame($parrain->id, $log->auditable_id);
        $this->assertSame($this->user->id, $log->actor_id);
    }

    public function test_un_refus_du_service_revient_sur_le_champ_compte(): void
    {
        $parrain = $this->parrain();
        $compte = $this->compte(attributs: ['is_active' => false]);

        $this->actingAs($this->user)
            ->post(route('parrains.compte.store', $parrain), ['compte_id' => $compte->id, 'identite_verifiee' => true])
            ->assertSessionHasErrors('compte');

        $this->assertNull($parrain->fresh()->user_id);
    }

    public function test_un_compte_ne_peut_pas_servir_deux_parrains(): void
    {
        $compte = $this->compte();
        $this->parrain()->forceFill(['user_id' => $compte->id])->save();
        $second = $this->parrain();

        $this->actingAs($this->user)
            ->post(route('parrains.compte.store', $second), ['compte_id' => $compte->id, 'identite_verifiee' => true])
            ->assertSessionHasErrors('compte');

        $this->assertNull($second->fresh()->user_id);
    }

    public function test_le_detachement_coupe_l_acces_et_garde_le_compte_et_la_trace(): void
    {
        $compte = $this->compte();
        $parrain = $this->parrain();
        $parrain->forceFill(['user_id' => $compte->id])->save();

        $this->actingAs($this->user)
            ->delete(route('parrains.compte.destroy', $parrain))
            ->assertRedirect();

        $this->assertNull($parrain->fresh()->user_id);
        $this->assertNotNull($compte->fresh());

        $log = AuditLog::where('event_code', AuditEvent::COMPTE_DETACHE->value)->sole();
        $this->assertSame($compte->id, $log->old_values['user_id']);
        $this->assertSame($compte->telephone, $log->meta['compte_telephone']);

        $this->actingAs($this->user)->get(route('parrains.show', $parrain))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('compte', null)
                ->has('historique_compte', 1)
                ->where('historique_compte.0.compte_telephone', $compte->telephone));
    }

    public function test_gerer_un_compte_exige_son_droit_propre(): void
    {
        $lecteur = $this->utilisateurAvec(['parrains.read']);
        $this->donnerToutesLesAgences($lecteur);
        $parrain = $this->parrain();
        $compte = $this->compte();

        $this->actingAs($lecteur)->get(route('parrains.show', $parrain))->assertOk();
        $this->actingAs($lecteur)->getJson(route('parrains.compte.candidat', $parrain).'?telephone=%2B224620000321')->assertForbidden();
        $this->actingAs($lecteur)
            ->post(route('parrains.compte.store', $parrain), ['compte_id' => $compte->id, 'identite_verifiee' => true])
            ->assertForbidden();
        $this->actingAs($lecteur)->delete(route('parrains.compte.destroy', $parrain))->assertForbidden();

        $this->assertNull($parrain->fresh()->user_id);
    }
}
