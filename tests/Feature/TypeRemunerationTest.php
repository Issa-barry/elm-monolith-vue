<?php

namespace Tests\Feature;

use App\Enums\StatutTypeRemuneration;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TypeRemuneration;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Rh\TypeRemunerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Référentiel des primes et avantages (docs/primes-avantages.md, ADR 0038) : un type n'est
 * attribuable qu'une fois validé, la validation est réservée au droit dédié, jamais contournable
 * par une requête directe, et l'organisation est toujours cloisonnée.
 */
class TypeRemunerationTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    /** Utilisateur RH : gère le référentiel, sans droit de validation. */
    private const GESTION = [
        'rh-types-remuneration.create', 'rh-types-remuneration.read',
        'rh-types-remuneration.update', 'rh-types-remuneration.delete',
    ];

    private const VALIDATION = 'rh-types-remuneration.valider';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => self::VALIDATION, 'guard_name' => 'web']);
        $this->initOrgAndUser(self::GESTION);
        $this->site = Site::where('organization_id', $this->org->id)->firstOrFail();
    }

    // ── Aides ─────────────────────────────────────────────────────────────────

    private function utilisateur(array $permissions, ?Organization $org = null): User
    {
        $org ??= $this->org;
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo($permissions);
        $this->rattacher($user, $org);

        return $user;
    }

    private function superAdmin(?Organization $org = null): User
    {
        $org ??= $this->org;
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');
        $this->rattacher($user, $org);

        return $user;
    }

    private function rattacher(User $user, Organization $org): void
    {
        $site = Site::where('organization_id', $org->id)->first()
            ?? Site::create(['organization_id' => $org->id, 'nom' => 'Ailleurs', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function donnees(array $surcharge = []): array
    {
        return [
            'libelle' => 'Prime de transport',
            'description' => 'Versée aux salariés qui se déplacent.',
            'categorie' => 'prime',
            'mode_calcul' => 'montant_fixe',
            'periodicite' => 'mensuelle',
            'nature_montant' => 'fixe',
            'proratisable' => true,
            'cumul_autorise' => false,
            'montant_defaut' => 200000,
            ...$surcharge,
        ];
    }

    private function creer(array $surcharge = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)
            ->from(route('types-remuneration.index'))
            ->post(route('types-remuneration.store'), $this->donnees($surcharge));
    }

    private function type(array $attributs = [], ?Organization $org = null): TypeRemuneration
    {
        return TypeRemuneration::create([
            'organization_id' => ($org ?? $this->org)->id,
            'code' => 'PRIME_RENDEMENT',
            'libelle' => 'Prime de rendement',
            'categorie' => 'prime',
            'mode_calcul' => 'montant_fixe',
            'periodicite' => 'mensuelle',
            'nature_montant' => 'variable',
            'statut' => StatutTypeRemuneration::A_VALIDER->value,
            ...$attributs,
        ]);
    }

    private function statut(TypeRemuneration $type, string $action, User $user)
    {
        return $this->actingAs($user)
            ->from(route('types-remuneration.index'))
            ->patch(route('types-remuneration.statut', [$type, $action]));
    }

    private function evenements(TypeRemuneration $type): array
    {
        return AuditLog::where('auditable_id', $type->id)->orderBy('created_at')->orderBy('id')->pluck('event_code')->all();
    }

    // ── Création et validation ────────────────────────────────────────────────

    public function test_un_type_cree_par_un_utilisateur_rh_est_a_valider_et_non_attribuable(): void
    {
        $this->creer()->assertRedirect()->assertSessionHasNoErrors();

        $type = TypeRemuneration::firstOrFail();
        $this->assertSame(StatutTypeRemuneration::A_VALIDER, $type->statut);
        $this->assertSame('PRIME_DE_TRANSPORT', $type->code);
        $this->assertSame($this->user->id, $type->cree_par);
        $this->assertNull($type->valide_par);
        $this->assertFalse($type->estAttribuable(now()));
        $this->assertSame(0, TypeRemuneration::attribuables($this->org->id, now())->count());
        $this->assertSame(['created'], $this->evenements($type));
    }

    public function test_sans_droit_de_validation_creer_et_valider_est_refuse_sans_rien_creer(): void
    {
        $this->creer(['valider' => true])->assertForbidden();

        $this->assertSame(0, TypeRemuneration::count());
    }

    public function test_le_super_admin_cree_et_valide_en_une_etape(): void
    {
        $super = $this->superAdmin();

        $this->creer(['valider' => true], $super)->assertRedirect()->assertSessionHasNoErrors();

        $type = TypeRemuneration::firstOrFail();
        $this->assertSame(StatutTypeRemuneration::ACTIF, $type->statut);
        $this->assertSame($super->id, $type->valide_par);
        $this->assertNotNull($type->valide_le);
        $this->assertTrue($type->estAttribuable(now()));
        $this->assertSame(['created', 'validated'], $this->evenements($type));
    }

    public function test_le_super_admin_valide_le_type_propose_par_un_rh(): void
    {
        $this->creer();
        $type = TypeRemuneration::firstOrFail();
        $super = $this->superAdmin();

        $this->statut($type, 'valider', $super)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(StatutTypeRemuneration::ACTIF, $type->fresh()->statut);
        $this->assertSame($super->id, $type->fresh()->valide_par);
        $this->assertSame(['created', 'validated'], $this->evenements($type));
    }

    public function test_la_validation_est_deleguable_par_la_permission_dediee(): void
    {
        $type = $this->type();
        $delegue = $this->utilisateur([...self::GESTION, self::VALIDATION]);

        $this->statut($type, 'valider', $delegue)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(StatutTypeRemuneration::ACTIF, $type->fresh()->statut);
    }

    public function test_requetes_directes_sans_droit_de_validation_refusees(): void
    {
        $aValider = $this->type();
        $actif = $this->type(['code' => 'PRIME_X', 'libelle' => 'Prime X', 'statut' => 'actif']);
        $ferme = $this->type(['code' => 'PRIME_Y', 'libelle' => 'Prime Y', 'statut' => 'ferme']);

        $this->statut($aValider, 'valider', $this->user)->assertForbidden();
        $this->statut($actif, 'fermer', $this->user)->assertForbidden();
        $this->statut($actif, 'desactiver', $this->user)->assertForbidden();
        $this->statut($ferme, 'reactiver', $this->user)->assertForbidden();

        $this->assertSame(StatutTypeRemuneration::A_VALIDER, $aValider->fresh()->statut);
        $this->assertSame(StatutTypeRemuneration::ACTIF, $actif->fresh()->statut);
        $this->assertSame(StatutTypeRemuneration::FERME, $ferme->fresh()->statut);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_action_de_statut_inconnue_introuvable(): void
    {
        $type = $this->type();

        $this->statut($type, 'supprimer', $this->superAdmin())->assertNotFound();
    }

    // ── Cycle de vie ──────────────────────────────────────────────────────────

    public function test_cycle_fermer_desactiver_reactiver(): void
    {
        $super = $this->superAdmin();
        $type = $this->type(['statut' => 'actif']);

        $this->statut($type, 'fermer', $super)->assertSessionHasNoErrors();
        $this->assertSame(StatutTypeRemuneration::FERME, $type->fresh()->statut);
        $this->assertFalse($type->fresh()->estAttribuable(now()));

        $this->statut($type, 'desactiver', $super)->assertSessionHasNoErrors();
        $this->assertSame(StatutTypeRemuneration::INACTIF, $type->fresh()->statut);

        $this->statut($type, 'reactiver', $super)->assertSessionHasNoErrors();
        $this->assertSame(StatutTypeRemuneration::ACTIF, $type->fresh()->statut);
        $this->assertSame('PRIME_RENDEMENT', $type->fresh()->code);
        $this->assertSame(['status_changed', 'status_changed', 'status_changed'], $this->evenements($type));
    }

    public function test_transitions_impossibles_refusees(): void
    {
        $super = $this->superAdmin();
        $aValider = $this->type();
        $actif = $this->type(['code' => 'PRIME_X', 'libelle' => 'Prime X', 'statut' => 'actif']);
        $inactif = $this->type(['code' => 'PRIME_Y', 'libelle' => 'Prime Y', 'statut' => 'inactif']);

        // Un type à valider ne peut être ni fermé, ni désactivé, ni réactivé : il se valide ou se supprime.
        $this->statut($aValider, 'fermer', $super)->assertSessionHasErrors('statut');
        $this->statut($aValider, 'desactiver', $super)->assertSessionHasErrors('statut');
        $this->statut($aValider, 'reactiver', $super)->assertSessionHasErrors('statut');
        $this->statut($actif, 'valider', $super)->assertSessionHasErrors('statut');
        $this->statut($actif, 'reactiver', $super)->assertSessionHasErrors('statut');
        $this->statut($inactif, 'fermer', $super)->assertSessionHasErrors('statut');

        $this->assertSame(StatutTypeRemuneration::A_VALIDER, $aValider->fresh()->statut);
        $this->assertSame(StatutTypeRemuneration::ACTIF, $actif->fresh()->statut);
        $this->assertSame(StatutTypeRemuneration::INACTIF, $inactif->fresh()->statut);
    }

    // ── Un type non validé n'est jamais utilisable comme un type validé ───────

    public function test_seuls_les_types_actifs_dans_leur_fenetre_sont_attribuables(): void
    {
        Carbon::setTestNow('2026-11-15');
        $this->type(['code' => 'A_VALIDER', 'libelle' => 'À valider']);
        $this->type(['code' => 'FERME', 'libelle' => 'Fermé', 'statut' => 'ferme']);
        $this->type(['code' => 'INACTIF', 'libelle' => 'Inactif', 'statut' => 'inactif']);
        $this->type(['code' => 'FUTUR', 'libelle' => 'Futur', 'statut' => 'actif', 'date_debut_application' => '2026-12-01']);
        $this->type(['code' => 'EXPIRE', 'libelle' => 'Expiré', 'statut' => 'actif', 'date_fin_application' => '2026-11-14']);
        $ouvert = $this->type(['code' => 'OUVERT', 'libelle' => 'Ouvert', 'statut' => 'actif']);
        $fenetre = $this->type(['code' => 'FENETRE', 'libelle' => 'Fenêtre', 'statut' => 'actif',
            'date_debut_application' => '2026-11-15', 'date_fin_application' => '2026-11-15']);
        $this->type(['code' => 'AILLEURS', 'libelle' => 'Ailleurs', 'statut' => 'actif'], Organization::factory()->create());

        $attribuables = TypeRemuneration::attribuables($this->org->id, now())->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$ouvert->id, $fenetre->id])->sort()->values()->all(), $attribuables);
        foreach (TypeRemuneration::where('organization_id', $this->org->id)->get() as $type) {
            $this->assertSame(in_array($type->id, $attribuables, true), $type->estAttribuable(now()), $type->code);
        }
        Carbon::setTestNow();
    }

    // ── Doublons ──────────────────────────────────────────────────────────────

    public function test_code_en_double_refuse_y_compris_avec_un_type_inactif(): void
    {
        $this->type(['code' => 'PRIME_TRANSPORT', 'libelle' => 'Ancienne prime', 'statut' => 'inactif']);

        // « prime-transport » est normalisé en PRIME_TRANSPORT.
        $this->creer(['code' => 'prime-transport'])->assertSessionHasErrors('code');

        $this->assertSame(1, TypeRemuneration::count());
    }

    public function test_libelle_en_double_refuse_sans_tenir_compte_de_la_casse(): void
    {
        $this->creer();
        $this->creer(['libelle' => '  PRIME DE TRANSPORT ', 'code' => 'AUTRE'])->assertSessionHasErrors('libelle');

        $this->assertSame(1, TypeRemuneration::count());
    }

    public function test_code_propose_rendu_unique_par_suffixe(): void
    {
        $this->type(['code' => 'PRIME_DE_TRANSPORT', 'libelle' => 'Prime de transport (ancienne)']);

        $this->creer()->assertSessionHasNoErrors();

        $this->assertTrue(TypeRemuneration::where('code', 'PRIME_DE_TRANSPORT_2')->exists());
    }

    public function test_le_meme_code_reste_possible_dans_une_autre_organisation(): void
    {
        $this->type(['code' => 'PRIME_DE_TRANSPORT', 'libelle' => 'Prime de transport'], Organization::factory()->create());

        $this->creer()->assertSessionHasNoErrors();

        $this->assertTrue(TypeRemuneration::where('organization_id', $this->org->id)->where('code', 'PRIME_DE_TRANSPORT')->exists());
    }

    public function test_un_code_sans_lettre_ni_chiffre_est_refuse(): void
    {
        $this->creer(['code' => '---'])->assertSessionHasErrors('code');
    }

    // ── Règles de saisie ──────────────────────────────────────────────────────

    public function test_regles_de_saisie(): void
    {
        $this->creer(['categorie' => 'salaire'])->assertSessionHasErrors('categorie');
        $this->creer(['mode_calcul' => 'pourcentage_salaire_base', 'taux_defaut' => 120])->assertSessionHasErrors('taux_defaut');
        $this->creer(['montant_defaut' => 0])->assertSessionHasErrors('montant_defaut');
        $this->creer(['date_debut_application' => '2026-12-01', 'date_fin_application' => '2026-11-30'])
            ->assertSessionHasErrors('date_fin_application');
        $this->creer(['libelle' => ''])->assertSessionHasErrors('libelle');

        $this->assertSame(0, TypeRemuneration::count());
    }

    public function test_une_seule_valeur_par_defaut_selon_le_mode_de_calcul(): void
    {
        $this->creer(['mode_calcul' => 'pourcentage_salaire_base', 'taux_defaut' => 10, 'montant_defaut' => 50000])
            ->assertSessionHasNoErrors();

        $type = TypeRemuneration::firstOrFail();
        $this->assertNull($type->montant_defaut);
        $this->assertEquals(10, $type->taux_defaut);
    }

    // ── Modification ──────────────────────────────────────────────────────────

    public function test_le_rh_modifie_sa_proposition_qui_reste_a_valider(): void
    {
        $this->creer();
        $type = TypeRemuneration::firstOrFail();

        $this->actingAs($this->user)->from(route('types-remuneration.index'))
            ->put(route('types-remuneration.update', $type), $this->donnees(['montant_defaut' => 250000]))
            ->assertSessionHasNoErrors();

        $type->refresh();
        $this->assertEquals(250000, $type->montant_defaut);
        $this->assertSame(StatutTypeRemuneration::A_VALIDER, $type->statut);
        $journal = AuditLog::where('auditable_id', $type->id)->where('event_code', 'updated')->firstOrFail();
        $this->assertSame(['montant_defaut'], array_keys($journal->new_values));
    }

    public function test_un_type_valide_ne_se_modifie_qu_avec_le_droit_de_validation(): void
    {
        $type = $this->type(['statut' => 'actif', 'montant_defaut' => 100000]);

        $this->actingAs($this->user)
            ->put(route('types-remuneration.update', $type), $this->donnees(['libelle' => 'Prime de rendement', 'montant_defaut' => 999999]))
            ->assertForbidden();
        $this->assertEquals(100000, $type->fresh()->montant_defaut);

        $super = $this->superAdmin();
        $this->actingAs($super)->from(route('types-remuneration.index'))
            ->put(route('types-remuneration.update', $type), $this->donnees(['libelle' => 'Prime de rendement', 'montant_defaut' => 150000]))
            ->assertSessionHasNoErrors();
        $this->assertEquals(150000, $type->fresh()->montant_defaut);
        $this->assertSame(StatutTypeRemuneration::ACTIF, $type->fresh()->statut);
    }

    public function test_un_type_ferme_ou_inactif_ne_se_modifie_pas_sans_droit_de_validation(): void
    {
        $inactif = $this->type(['statut' => 'inactif']);

        $this->actingAs($this->user)
            ->put(route('types-remuneration.update', $inactif), $this->donnees(['libelle' => 'Prime de rendement']))
            ->assertForbidden();
    }

    public function test_enregistrer_et_valider_une_proposition(): void
    {
        $type = $this->type();

        $this->actingAs($this->user)
            ->put(route('types-remuneration.update', $type), $this->donnees(['libelle' => 'Prime de rendement', 'valider' => true]))
            ->assertForbidden();
        $this->assertSame(StatutTypeRemuneration::A_VALIDER, $type->fresh()->statut);

        $this->actingAs($this->superAdmin())->from(route('types-remuneration.index'))
            ->put(route('types-remuneration.update', $type), $this->donnees(['libelle' => 'Prime de rendement', 'valider' => true]))
            ->assertSessionHasNoErrors();
        $this->assertSame(StatutTypeRemuneration::ACTIF, $type->fresh()->statut);
    }

    public function test_un_code_vide_a_la_modification_conserve_le_code_actuel(): void
    {
        $type = $this->type();

        $this->actingAs($this->user)->from(route('types-remuneration.index'))
            ->put(route('types-remuneration.update', $type), $this->donnees(['libelle' => 'Prime de rendement', 'code' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame('PRIME_RENDEMENT', $type->fresh()->code);
    }

    // ── Suppression ───────────────────────────────────────────────────────────

    public function test_le_rh_supprime_une_proposition_jamais_attribuee(): void
    {
        $type = $this->type();

        $this->actingAs($this->user)->from(route('types-remuneration.index'))
            ->delete(route('types-remuneration.destroy', $type))
            ->assertSessionHasNoErrors();

        $this->assertNull($type->fresh());
        $this->assertSame(['deleted'], $this->evenements($type));
    }

    public function test_supprimer_un_type_valide_exige_le_droit_de_validation(): void
    {
        $type = $this->type(['statut' => 'actif']);

        $this->actingAs($this->user)->delete(route('types-remuneration.destroy', $type))->assertForbidden();
        $this->assertNotNull($type->fresh());

        $this->actingAs($this->superAdmin())->from(route('types-remuneration.index'))
            ->delete(route('types-remuneration.destroy', $type))
            ->assertSessionHasNoErrors();
        $this->assertNull($type->fresh());
    }

    public function test_un_type_deja_attribue_ne_se_supprime_pas_et_garde_ses_caracteristiques(): void
    {
        $type = $this->type(['statut' => 'actif']);
        // Les attributions arrivent au lot 3 : on simule un type déjà attribué.
        $this->app->instance(TypeRemunerationService::class, new class(app(AuditLogService::class)) extends TypeRemunerationService
        {
            protected function estUtilise(TypeRemuneration $type): bool
            {
                return true;
            }
        });
        $super = $this->superAdmin();

        $this->actingAs($super)->from(route('types-remuneration.index'))
            ->delete(route('types-remuneration.destroy', $type))
            ->assertSessionHasErrors('type');
        $this->assertNotNull($type->fresh());

        // Libellé et description restent modifiables ; le mode de calcul est figé.
        $this->actingAs($super)->from(route('types-remuneration.index'))
            ->put(route('types-remuneration.update', $type), $this->donnees([
                'libelle' => 'Prime de rendement', 'nature_montant' => 'variable', 'mode_calcul' => 'pourcentage_salaire_base',
            ]))
            ->assertSessionHasErrors('mode_calcul');
        $this->actingAs($super)->from(route('types-remuneration.index'))
            ->put(route('types-remuneration.update', $type), $this->donnees([
                'libelle' => 'Prime de rendement mensuelle', 'nature_montant' => 'variable', 'code' => '',
            ]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Prime de rendement mensuelle', $type->fresh()->libelle);
    }

    // ── Permissions et organisation ───────────────────────────────────────────

    public function test_permissions_de_base_controlees_cote_serveur(): void
    {
        $type = $this->type();
        $sansDroit = $this->utilisateur([]);
        $lecteur = $this->utilisateur(['rh-types-remuneration.read']);

        $this->actingAs($sansDroit)->get(route('types-remuneration.index'))->assertForbidden();
        $this->actingAs($sansDroit)->getJson(route('types-remuneration.historique', $type))->assertForbidden();

        $this->actingAs($lecteur)->get(route('types-remuneration.index'))->assertOk();
        $this->actingAs($lecteur)->post(route('types-remuneration.store'), $this->donnees(['libelle' => 'Autre']))->assertForbidden();
        // La permission est contrôlée avant la saisie : aucune erreur de champ ne fuit.
        $this->actingAs($lecteur)->post(route('types-remuneration.store'), ['libelle' => ''])->assertForbidden();
        $this->actingAs($lecteur)->put(route('types-remuneration.update', $type), $this->donnees())->assertForbidden();
        $this->actingAs($lecteur)->delete(route('types-remuneration.destroy', $type))->assertForbidden();
        $this->statut($type, 'valider', $lecteur)->assertForbidden();

        $this->assertSame(1, TypeRemuneration::count());
        $this->assertSame(StatutTypeRemuneration::A_VALIDER, $type->fresh()->statut);
    }

    public function test_cloisonnement_entre_organisations_y_compris_pour_le_super_admin(): void
    {
        $autreOrg = Organization::factory()->create();
        $etranger = $this->type(['statut' => 'a_valider'], $autreOrg);
        $super = $this->superAdmin();
        $rhAutreOrg = $this->utilisateur([...self::GESTION, self::VALIDATION], $autreOrg);
        $this->type(['code' => 'LOCAL', 'libelle' => 'Local']);

        $this->statut($etranger, 'valider', $super)->assertForbidden();
        $this->actingAs($super)->put(route('types-remuneration.update', $etranger), $this->donnees())->assertForbidden();
        $this->actingAs($super)->delete(route('types-remuneration.destroy', $etranger))->assertForbidden();
        $this->actingAs($super)->getJson(route('types-remuneration.historique', $etranger))->assertForbidden();

        $this->assertSame(StatutTypeRemuneration::A_VALIDER, $etranger->fresh()->statut);
        $this->assertSame('PRIME_RENDEMENT', $etranger->fresh()->code);

        // La liste ne montre que l'organisation de l'utilisateur.
        $this->actingAs($rhAutreOrg)->get(route('types-remuneration.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('TypesRemuneration/Index')
                ->has('types', 1)
                ->where('types.0.id', $etranger->id));
    }

    public function test_liste_et_historique(): void
    {
        $this->creer();
        $type = TypeRemuneration::firstOrFail();
        $this->statut($type, 'valider', $this->superAdmin());

        $this->actingAs($this->user)->get(route('types-remuneration.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('TypesRemuneration/Index')
                ->has('types', 1)
                ->where('types.0.code', 'PRIME_DE_TRANSPORT')
                ->where('types.0.statut', 'actif')
                ->where('types.0.attribuable', true)
                ->has('categories', 4)
                ->has('statuts', 4));

        $historique = $this->actingAs($this->user)->getJson(route('types-remuneration.historique', $type))
            ->assertOk()
            ->json();
        $this->assertSame(['validated', 'created'], array_column($historique, 'event_code'));
    }

    public function test_migration_de_rattrapage_reserve_la_validation_au_super_admin(): void
    {
        $roles = [];
        foreach (['super_admin', 'admin_entreprise', 'manager'] as $nom) {
            $roles[$nom] = Role::firstOrCreate(['name' => $nom, 'guard_name' => 'web']);
        }

        $migration = require database_path('migrations/2026_10_28_100100_backfill_permissions_types_remuneration.php');
        $migration->up();
        $migration->up(); // idempotente

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($roles['super_admin']->fresh()->hasPermissionTo(self::VALIDATION));
        $this->assertFalse($roles['admin_entreprise']->fresh()->hasPermissionTo(self::VALIDATION));
        foreach (self::GESTION as $p) {
            $this->assertTrue($roles['admin_entreprise']->fresh()->hasPermissionTo($p), $p);
        }
        $this->assertSame(0, $roles['manager']->fresh()->permissions()->count());
    }
}
