<?php

namespace Tests\Feature;

use App\Enums\StatutAffectationRemuneration;
use App\Enums\StatutSaisieRemuneration;
use App\Enums\StatutTypeRemuneration;
use App\Models\AffectationRemuneration;
use App\Models\AuditLog;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\PaiePeriode;
use App\Models\SaisieRemuneration;
use App\Models\Site;
use App\Models\TransmissionPaie;
use App\Models\TransmissionPaieLigne;
use App\Models\TypeRemuneration;
use App\Models\User;
use App\Services\Rh\AffectationRemunerationService;
use App\Services\Rh\TypeRemunerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\Feature\Concerns\SimuleUnePanneDuJournal;
use Tests\TestCase;

/**
 * Affectations des primes et avantages aux salariés (docs/primes-avantages.md, ADR 0039) : seul un
 * type validé et actif s'attribue, dans sa période d'application et la période d'emploi ; pas de
 * chevauchement sans cumul ; l'historique n'est jamais réécrit (changement de montant = nouvelle
 * affectation, fin d'effet validée) ; aucun mois figé n'est touché ; validation réservée au droit
 * dédié ; organisation et agence cloisonnées.
 */
class AffectationRemunerationTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase, SimuleUnePanneDuJournal;

    private const GESTION = [
        'rh-affectations-remuneration.create', 'rh-affectations-remuneration.read',
        'rh-affectations-remuneration.update', 'rh-affectations-remuneration.delete',
    ];

    private const VALIDATION = 'rh-affectations-remuneration.valider';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([self::VALIDATION, 'rh-types-remuneration.valider', 'rh-types-remuneration.delete', 'rh-types-remuneration.read'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $this->initOrgAndUser(self::GESTION);
        $this->site = Site::where('organization_id', $this->org->id)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Aides ─────────────────────────────────────────────────────────────────

    private function superAdmin(?Organization $org = null): User
    {
        $org ??= $this->org;
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');
        $site = Site::where('organization_id', $org->id)->first()
            ?? Site::create(['organization_id' => $org->id, 'nom' => 'Autre', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    /** Utilisateur limité à une agence (rôle manager, sans périmètre toutes agences). */
    private function managerAgence(Site $site, array $permissions): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $site->organization_id]);
        $user->assignRole('manager');
        $user->givePermissionTo($permissions);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function employe(array $attributs = [], ?Organization $org = null, ?Site $site = null): Employe
    {
        $org ??= $this->org;

        return Employe::factory()->create([
            'organization_id' => $org->id,
            'site_id' => ($site ?? ($org->is($this->org) ? $this->site : Site::where('organization_id', $org->id)->first()))?->id,
            'date_embauche' => '2025-01-01',
            ...$attributs,
        ]);
    }

    private function type(array $attributs = [], ?Organization $org = null): TypeRemuneration
    {
        static $n = 0;
        $n++;

        return TypeRemuneration::create([
            'organization_id' => ($org ?? $this->org)->id,
            'code' => 'PRIME_'.$n,
            'libelle' => 'Prime n°'.$n,
            'categorie' => 'prime',
            'mode_calcul' => 'montant_fixe',
            'periodicite' => 'mensuelle',
            'nature_montant' => 'fixe',
            'proratisable' => true,
            'statut' => StatutTypeRemuneration::ACTIF->value,
            ...$attributs,
        ]);
    }

    private function affecter(array $donnees, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)
            ->from(route('affectations-remuneration.index'))
            ->post(route('affectations-remuneration.store'), $donnees);
    }

    private function affectationValidee(Employe $employe, TypeRemuneration $type, array $attributs = []): AffectationRemuneration
    {
        return AffectationRemuneration::create([
            'organization_id' => $employe->organization_id,
            'employe_id' => $employe->id,
            'type_remuneration_id' => $type->id,
            'date_debut' => '2026-10-01',
            'montant' => 500000,
            'statut' => StatutAffectationRemuneration::VALIDEE->value,
            ...$attributs,
        ]);
    }

    private function figerOctobre(): void
    {
        PaiePeriode::create(['organization_id' => $this->org->id, 'annee' => 2026, 'mois' => 10, 'statut' => 'valide_rh']);
    }

    // ── Attribution et validation ─────────────────────────────────────────────

    public function test_un_rh_attribue_un_type_valide_qui_attend_sa_validation(): void
    {
        $employe = $this->employe();
        $type = $this->type();

        $this->affecter(['employe_id' => $employe->id, 'type_remuneration_id' => $type->id, 'date_debut' => '2026-11-01', 'montant' => 500000])
            ->assertSessionHasNoErrors();

        $affectation = AffectationRemuneration::sole();
        $this->assertSame(StatutAffectationRemuneration::A_VALIDER, $affectation->statut);
        $this->assertSame('500000.00', $affectation->montant);
        $this->assertSame($this->user->id, $affectation->cree_par);
        $this->assertNull($affectation->valide_par);
        $this->assertTrue($affectation->attendValidation());
        $this->assertSame(1, AuditLog::where('auditable_id', $affectation->id)->where('event_code', 'created')->count());
    }

    public function test_le_super_admin_attribue_et_valide_en_une_etape(): void
    {
        $super = $this->superAdmin();
        $employe = $this->employe();
        $type = $this->type();

        $this->affecter(['employe_id' => $employe->id, 'type_remuneration_id' => $type->id, 'date_debut' => '2026-11-01', 'montant' => 500000, 'valider' => true], $super)
            ->assertSessionHasNoErrors();

        $affectation = AffectationRemuneration::sole();
        $this->assertSame(StatutAffectationRemuneration::VALIDEE, $affectation->statut);
        $this->assertSame($super->id, $affectation->valide_par);
        $this->assertSame(['created', 'validated'], AuditLog::where('auditable_id', $affectation->id)->orderBy('created_at')->orderBy('id')->pluck('event_code')->sort()->values()->all());
    }

    public function test_la_validation_exige_le_droit_dedie_meme_par_requete_directe(): void
    {
        $employe = $this->employe();
        $type = $this->type();

        $this->affecter(['employe_id' => $employe->id, 'type_remuneration_id' => $type->id, 'date_debut' => '2026-11-01', 'montant' => 500000, 'valider' => true])
            ->assertForbidden();
        $this->assertSame(0, AffectationRemuneration::count());

        $this->affecter(['employe_id' => $employe->id, 'type_remuneration_id' => $type->id, 'date_debut' => '2026-11-01', 'montant' => 500000]);
        $affectation = AffectationRemuneration::sole();
        $this->actingAs($this->user)->patch(route('affectations-remuneration.valider', $affectation))->assertForbidden();
        $this->assertSame(StatutAffectationRemuneration::A_VALIDER, $affectation->fresh()->statut);

        // Délégation : le même utilisateur, une fois le droit accordé, valide sa propre proposition.
        $this->user->givePermissionTo(self::VALIDATION);
        $this->actingAs($this->user)->patch(route('affectations-remuneration.valider', $affectation))->assertSessionHasNoErrors();
        $this->assertSame(StatutAffectationRemuneration::VALIDEE, $affectation->fresh()->statut);
    }

    public function test_un_type_non_valide_ferme_ou_inactif_ne_s_attribue_pas(): void
    {
        $employe = $this->employe();

        foreach (['a_valider' => 'pas encore validé', 'ferme' => 'fermé', 'inactif' => 'désactivé'] as $statut => $message) {
            $type = $this->type(['statut' => $statut]);
            $this->affecter(['employe_id' => $employe->id, 'type_remuneration_id' => $type->id, 'date_debut' => '2026-11-01', 'montant' => 100000])
                ->assertSessionHasErrors('type_remuneration_id');
            $this->assertStringContainsString($message, session('errors')->first('type_remuneration_id'));
        }
        $this->assertSame(0, AffectationRemuneration::count());
    }

    public function test_une_proposition_dont_le_type_a_ete_ferme_entre_temps_ne_se_valide_plus(): void
    {
        $super = $this->superAdmin();
        $employe = $this->employe();
        $type = $this->type();
        $this->affecter(['employe_id' => $employe->id, 'type_remuneration_id' => $type->id, 'date_debut' => '2026-11-01', 'montant' => 100000]);
        $type->update(['statut' => StatutTypeRemuneration::FERME->value]);

        $this->actingAs($super)->from(route('affectations-remuneration.index'))
            ->patch(route('affectations-remuneration.valider', AffectationRemuneration::sole()))
            ->assertSessionHasErrors('type_remuneration_id');
        $this->assertSame(StatutAffectationRemuneration::A_VALIDER, AffectationRemuneration::sole()->statut);
    }

    public function test_la_periode_d_application_du_type_est_respectee(): void
    {
        $employe = $this->employe();
        $type = $this->type(['date_debut_application' => '2026-11-15', 'date_fin_application' => '2026-12-31']);
        $base = ['employe_id' => $employe->id, 'type_remuneration_id' => $type->id, 'montant' => 100000];

        $this->affecter([...$base, 'date_debut' => '2026-11-01', 'date_fin' => '2026-12-31'])->assertSessionHasErrors('date_debut');
        $this->affecter([...$base, 'date_debut' => '2026-11-15'])->assertSessionHasErrors('date_fin');
        $this->affecter([...$base, 'date_debut' => '2026-11-15', 'date_fin' => '2027-01-31'])->assertSessionHasErrors('date_fin');
        $this->affecter([...$base, 'date_debut' => '2026-11-15', 'date_fin' => '2026-12-31'])->assertSessionHasNoErrors();
    }

    public function test_les_dates_d_effet_respectent_la_periode_d_emploi(): void
    {
        $type = $this->type();
        $employe = $this->employe(['date_embauche' => '2026-11-05', 'date_sortie' => '2026-12-20']);
        $base = ['employe_id' => $employe->id, 'type_remuneration_id' => $type->id, 'montant' => 100000];

        $this->affecter([...$base, 'date_debut' => '2026-11-10', 'date_fin' => '2026-11-01'])->assertSessionHasErrors('date_fin');
        $this->affecter([...$base, 'date_debut' => '2026-11-01'])->assertSessionHasErrors('date_debut');
        $this->affecter([...$base, 'date_debut' => '2026-12-21'])->assertSessionHasErrors('date_debut');
        $this->affecter([...$base, 'date_debut' => '2026-11-05', 'date_fin' => '2026-12-31'])->assertSessionHasErrors('date_fin');
        $this->affecter([...$base, 'date_debut' => '2026-11-05', 'date_fin' => '2026-12-20'])->assertSessionHasNoErrors();

        $sorti = $this->employe(['statut' => 'sorti', 'date_sortie' => null]);
        $this->affecter([...$base, 'employe_id' => $sorti->id, 'date_debut' => '2026-11-05'])->assertSessionHasErrors('employe_id');
    }

    public function test_pas_de_chevauchement_du_meme_type_sans_cumul(): void
    {
        $employe = $this->employe();
        $type = $this->type();
        $this->affectationValidee($employe, $type, ['date_debut' => '2026-10-01', 'date_fin' => '2026-12-31']);
        $base = ['employe_id' => $employe->id, 'type_remuneration_id' => $type->id, 'montant' => 100000];

        $this->affecter([...$base, 'date_debut' => '2026-12-15'])->assertSessionHasErrors('date_debut');
        $this->affecter([...$base, 'date_debut' => '2027-01-01'])->assertSessionHasNoErrors();
        // La proposition en attente occupe aussi la période.
        $this->affecter([...$base, 'date_debut' => '2027-02-01'])->assertSessionHasErrors('date_debut');
        $this->assertStringContainsString('proposition à valider', session('errors')->first('date_debut'));

        // Un autre salarié, ou un autre type : aucun conflit.
        $this->affecter([...$base, 'employe_id' => $this->employe()->id, 'date_debut' => '2026-11-01'])->assertSessionHasNoErrors();
        $this->affecter([...$base, 'type_remuneration_id' => $this->type()->id, 'date_debut' => '2026-11-01'])->assertSessionHasNoErrors();
    }

    public function test_le_cumul_autorise_permet_plusieurs_affectations_simultanees(): void
    {
        $employe = $this->employe();
        $type = $this->type(['cumul_autorise' => true]);
        $base = ['employe_id' => $employe->id, 'type_remuneration_id' => $type->id, 'date_debut' => '2026-11-01'];

        $this->affecter([...$base, 'montant' => 100000])->assertSessionHasNoErrors();
        $this->affecter([...$base, 'montant' => 50000])->assertSessionHasNoErrors();
        $this->assertSame(2, AffectationRemuneration::count());
    }

    public function test_montant_taux_ou_saisie_mensuelle_selon_le_type(): void
    {
        $employe = $this->employe();
        $fixe = $this->type(['montant_defaut' => 200000]);
        $pourcentage = $this->type(['mode_calcul' => 'pourcentage_salaire_base', 'taux_defaut' => 10]);
        $variable = $this->type(['nature_montant' => 'variable']);
        $base = ['employe_id' => $employe->id, 'date_debut' => '2026-11-01'];

        // La valeur par défaut du type n'est jamais reprise d'office.
        $this->affecter([...$base, 'type_remuneration_id' => $fixe->id])->assertSessionHasErrors('montant');
        $this->affecter([...$base, 'type_remuneration_id' => $pourcentage->id])->assertSessionHasErrors('taux');
        $this->affecter([...$base, 'type_remuneration_id' => $variable->id, 'montant' => 100000])->assertSessionHasErrors('montant');

        $this->affecter([...$base, 'type_remuneration_id' => $pourcentage->id, 'taux' => 12.5])->assertSessionHasNoErrors();
        $this->affecter([...$base, 'type_remuneration_id' => $variable->id])->assertSessionHasNoErrors();

        $this->assertSame('12.50', AffectationRemuneration::where('type_remuneration_id', $pourcentage->id)->sole()->taux);
        $sansMontant = AffectationRemuneration::where('type_remuneration_id', $variable->id)->sole();
        $this->assertNull($sansMontant->montant);
        $this->assertNull($sansMontant->taux);
    }

    public function test_une_prime_ponctuelle_couvre_un_seul_mois(): void
    {
        $type = $this->type(['periodicite' => 'ponctuelle']);
        $this->affecter(['employe_id' => $this->employe()->id, 'type_remuneration_id' => $type->id, 'date_debut' => '2026-12-17', 'date_fin' => '2027-06-30', 'montant' => 300000])
            ->assertSessionHasNoErrors();

        $affectation = AffectationRemuneration::sole();
        $this->assertSame('2026-12-01', $affectation->date_debut->toDateString());
        $this->assertSame('2026-12-31', $affectation->date_fin->toDateString());
    }

    public function test_un_avantage_en_nature_s_attribue_comme_valorise(): void
    {
        $type = $this->type(['categorie' => 'avantage_nature', 'montant_defaut' => 150000]);
        $this->affecter(['employe_id' => $this->employe()->id, 'type_remuneration_id' => $type->id, 'date_debut' => '2026-11-01', 'montant' => 150000])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->user)->get(route('affectations-remuneration.index'))
            ->assertInertia(fn (Assert $page) => $page->where('affectations.0.type.valorise', true));
    }

    // ── Historique : changement de montant, fin d'effet ───────────────────────

    public function test_un_changement_de_montant_preserve_les_periodes_ecoulees(): void
    {
        $super = $this->superAdmin();
        $employe = $this->employe();
        $type = $this->type();
        $initiale = $this->affectationValidee($employe, $type, ['date_debut' => '2026-10-01']);

        $this->actingAs($this->user)->from(route('affectations-remuneration.index'))
            ->post(route('affectations-remuneration.changement-montant', $initiale), ['date_effet' => '2026-11-15', 'montant' => 600000])
            ->assertSessionHasNoErrors();

        $nouvelle = AffectationRemuneration::where('remplace_affectation_id', $initiale->id)->sole();
        $this->assertSame(StatutAffectationRemuneration::A_VALIDER, $nouvelle->statut);
        // Tant que le changement n'est pas validé, l'ancien montant s'applique, sans fin.
        $this->assertNull($initiale->fresh()->date_fin);

        $this->actingAs($super)->patch(route('affectations-remuneration.valider', $nouvelle))->assertSessionHasNoErrors();

        $initiale->refresh();
        $nouvelle->refresh();
        $this->assertSame('2026-11-14', $initiale->date_fin->toDateString());
        $this->assertSame('500000.00', $initiale->montant);
        $this->assertSame('2026-11-15', $nouvelle->date_debut->toDateString());
        $this->assertSame('600000.00', $nouvelle->montant);
        $this->assertSame(StatutAffectationRemuneration::VALIDEE, $nouvelle->statut);
        $this->assertTrue(AuditLog::where('auditable_id', $initiale->id)->where('event_code', 'ended')->exists());
    }

    public function test_un_changement_de_montant_mal_date_ou_identique_est_refuse(): void
    {
        $employe = $this->employe();
        $type = $this->type();
        $affectation = $this->affectationValidee($employe, $type, ['date_debut' => '2026-10-01', 'date_fin' => '2026-12-31']);
        $url = route('affectations-remuneration.changement-montant', $affectation);
        $requete = fn (array $d) => $this->actingAs($this->user)->from(route('affectations-remuneration.index'))->post($url, $d);

        $requete(['date_effet' => '2026-10-01', 'montant' => 600000])->assertSessionHasErrors('date_effet');
        $requete(['date_effet' => '2027-01-01', 'montant' => 600000])->assertSessionHasErrors('date_effet');
        $requete(['date_effet' => '2026-11-15', 'montant' => 500000])->assertSessionHasErrors('montant');
        $requete(['date_effet' => '2026-11-15', 'montant' => 600000])->assertSessionHasNoErrors();
        // Un seul changement en attente à la fois.
        $requete(['date_effet' => '2026-12-01', 'montant' => 700000])->assertSessionHasErrors('affectation');
    }

    public function test_une_fin_d_effet_se_demande_puis_se_valide(): void
    {
        $super = $this->superAdmin();
        $affectation = $this->affectationValidee($this->employe(), $this->type(), ['date_debut' => '2026-10-01']);

        $this->actingAs($this->user)->from(route('affectations-remuneration.index'))
            ->post(route('affectations-remuneration.fin', $affectation), ['date_fin' => '2026-11-30', 'motif' => 'Changement de poste'])
            ->assertSessionHasNoErrors();

        $affectation->refresh();
        $this->assertNull($affectation->date_fin);
        $this->assertSame('2026-11-30', $affectation->date_fin_demandee->toDateString());

        $this->actingAs($this->user)->patch(route('affectations-remuneration.valider', $affectation))->assertForbidden();
        $this->actingAs($super)->patch(route('affectations-remuneration.valider', $affectation))->assertSessionHasNoErrors();

        $affectation->refresh();
        $this->assertSame('2026-11-30', $affectation->date_fin->toDateString());
        $this->assertNull($affectation->date_fin_demandee);
        $this->assertSame('Changement de poste', $affectation->motif_fin);
        $this->assertSame(StatutAffectationRemuneration::VALIDEE, $affectation->statut);
        $this->assertTrue(AuditLog::where('auditable_id', $affectation->id)->where('event_code', 'ended')->exists());
    }

    public function test_aucune_modification_ne_touche_un_mois_fige(): void
    {
        $this->figerOctobre();
        $employe = $this->employe();
        $type = $this->type();
        $affectation = $this->affectationValidee($employe, $type, ['date_debut' => '2026-09-01']);
        $depuis = fn () => $this->actingAs($this->user)->from(route('affectations-remuneration.index'));

        $this->affecter(['employe_id' => $employe->id, 'type_remuneration_id' => $this->type()->id, 'date_debut' => '2026-10-15', 'montant' => 1000])
            ->assertSessionHasErrors('date_debut');
        $this->assertStringContainsString('figée', session('errors')->first('date_debut'));

        $depuis()->post(route('affectations-remuneration.changement-montant', $affectation), ['date_effet' => '2026-10-20', 'montant' => 700000])
            ->assertSessionHasErrors('date_effet');
        $depuis()->post(route('affectations-remuneration.fin', $affectation), ['date_fin' => '2026-10-30', 'motif' => 'x'])
            ->assertSessionHasErrors('date_fin');
        // Dernier jour du mois figé : la fin d'effet n'arrête que des versements futurs.
        $depuis()->post(route('affectations-remuneration.fin', $affectation), ['date_fin' => '2026-10-31', 'motif' => 'x'])
            ->assertSessionHasNoErrors();
    }

    public function test_un_mois_fige_par_une_transmission_validee_bloque_aussi(): void
    {
        TransmissionPaie::create(['organization_id' => $this->org->id, 'annee' => 2026, 'mois' => 11, 'version' => 1, 'statut' => 'validee']);

        $this->affecter(['employe_id' => $this->employe()->id, 'type_remuneration_id' => $this->type()->id, 'date_debut' => '2026-11-20', 'montant' => 1000])
            ->assertSessionHasErrors('date_debut');
        $this->affecter(['employe_id' => $this->employe()->id, 'type_remuneration_id' => $this->type()->id, 'date_debut' => '2026-12-01', 'montant' => 1000])
            ->assertSessionHasNoErrors();
    }

    // ── Montants saisis chaque mois ───────────────────────────────────────────

    public function test_le_montant_d_un_element_variable_se_saisit_et_se_valide_chaque_mois(): void
    {
        $super = $this->superAdmin();
        $affectation = $this->affectationValidee($this->employe(), $this->type(['nature_montant' => 'variable']), ['montant' => null]);
        $saisir = fn (User $u, array $d) => $this->actingAs($u)->from(route('affectations-remuneration.index'))
            ->post(route('affectations-remuneration.saisies.store', $affectation), $d);

        $saisir($this->user, ['annee' => 2026, 'mois' => 11, 'montant' => 150000])->assertSessionHasNoErrors();
        $saisie = SaisieRemuneration::sole();
        $this->assertSame(StatutSaisieRemuneration::A_VALIDER, $saisie->statut);

        // Correction d'une saisie encore à valider : le même enregistrement, pas de doublon.
        $saisir($this->user, ['annee' => 2026, 'mois' => 11, 'montant' => 175000])->assertSessionHasNoErrors();
        $this->assertSame('175000.00', SaisieRemuneration::sole()->montant);

        $this->actingAs($this->user)->patch(route('affectations-remuneration.saisies.valider', $saisie))->assertForbidden();
        $this->actingAs($super)->patch(route('affectations-remuneration.saisies.valider', $saisie))->assertSessionHasNoErrors();
        $this->assertSame(StatutSaisieRemuneration::VALIDEE, $saisie->fresh()->statut);

        // Un montant validé ne se corrige qu'avec le droit de validation.
        $saisir($this->user, ['annee' => 2026, 'mois' => 11, 'montant' => 1])->assertForbidden();
        // Mois hors de l'affectation.
        $saisir($this->user, ['annee' => 2026, 'mois' => 9, 'montant' => 1])->assertSessionHasErrors('mois');
    }

    public function test_un_element_a_montant_fixe_n_a_pas_de_saisie_mensuelle(): void
    {
        $affectation = $this->affectationValidee($this->employe(), $this->type());
        $this->actingAs($this->user)->from(route('affectations-remuneration.index'))
            ->post(route('affectations-remuneration.saisies.store', $affectation), ['annee' => 2026, 'mois' => 11, 'montant' => 1])
            ->assertSessionHasErrors('mois');
    }

    // ── Annulation ────────────────────────────────────────────────────────────

    public function test_une_proposition_s_annule_et_reste_dans_l_historique(): void
    {
        $this->affecter(['employe_id' => $this->employe()->id, 'type_remuneration_id' => $this->type()->id, 'date_debut' => '2026-11-01', 'montant' => 1000]);
        $affectation = AffectationRemuneration::sole();

        $this->actingAs($this->user)->patch(route('affectations-remuneration.annuler', $affectation), ['motif' => 'Erreur de saisie'])
            ->assertSessionHasNoErrors();

        $affectation->refresh();
        $this->assertSame(StatutAffectationRemuneration::ANNULEE, $affectation->statut);
        $this->assertSame('Erreur de saisie', $affectation->motif_annulation);
        $this->assertSame(1, AffectationRemuneration::count());
        $this->assertTrue(AuditLog::where('auditable_id', $affectation->id)->where('event_code', 'cancelled')->exists());
    }

    public function test_une_affectation_validee_ne_s_annule_qu_avec_le_droit_et_jamais_une_fois_transmise(): void
    {
        $super = $this->superAdmin();
        $affectation = $this->affectationValidee($this->employe(), $this->type(), ['date_debut' => '2026-12-01']);

        $this->actingAs($this->user)->patch(route('affectations-remuneration.annuler', $affectation))->assertForbidden();

        $transmise = $this->affectationValidee($this->employe(), $this->type(), ['date_debut' => '2026-12-01']);
        $transmission = TransmissionPaie::create(['organization_id' => $this->org->id, 'annee' => 2026, 'mois' => 9, 'version' => 1, 'statut' => 'brouillon']);
        TransmissionPaieLigne::create([
            'transmission_paie_id' => $transmission->id, 'organization_id' => $this->org->id, 'employe_id' => $transmise->employe_id,
            'nom_salarie' => 'X', 'origine' => 'affectation', 'affectation_id' => $transmise->id, 'libelle' => 'X', 'versement' => 'verse',
            'du' => '2026-09-01', 'au' => '2026-09-30', 'jours' => 30, 'jours_mois' => 30, 'montant' => 1, 'detail_calcul' => 'x',
        ]);
        $transmission->update(['statut' => 'validee']);

        $this->actingAs($super)->from(route('affectations-remuneration.index'))
            ->patch(route('affectations-remuneration.annuler', $transmise))->assertSessionHasErrors('statut');
        $this->actingAs($super)->patch(route('affectations-remuneration.annuler', $affectation))->assertSessionHasNoErrors();
        $this->assertSame(StatutAffectationRemuneration::ANNULEE, $affectation->fresh()->statut);
    }

    public function test_annuler_un_changement_de_montant_valide_retablit_la_fin_precedente(): void
    {
        $super = $this->superAdmin();
        $initiale = $this->affectationValidee($this->employe(), $this->type(), ['date_debut' => '2026-10-01', 'date_fin' => '2027-06-30']);
        $this->actingAs($super)->post(route('affectations-remuneration.changement-montant', $initiale), ['date_effet' => '2026-12-01', 'montant' => 700000, 'valider' => true])
            ->assertSessionHasNoErrors();
        $nouvelle = AffectationRemuneration::where('remplace_affectation_id', $initiale->id)->sole();
        $this->assertSame('2026-11-30', $initiale->fresh()->date_fin->toDateString());

        $this->actingAs($super)->patch(route('affectations-remuneration.annuler', $nouvelle))->assertSessionHasNoErrors();

        $this->assertSame('2027-06-30', $initiale->fresh()->date_fin->toDateString());
        $this->assertSame(StatutAffectationRemuneration::ANNULEE, $nouvelle->fresh()->statut);
    }

    // ── Désactivation globale du type (lot 2 complété) ────────────────────────

    public function test_desactiver_un_type_attribue_exige_une_date_et_arrete_les_affectations(): void
    {
        $super = $this->superAdmin();
        $type = $this->type();
        $enCours = $this->affectationValidee($this->employe(), $type, ['date_debut' => '2026-10-01']);
        $future = $this->affectationValidee($this->employe(), $type, ['date_debut' => '2027-01-01']);
        $proposition = AffectationRemuneration::create([
            'organization_id' => $this->org->id, 'employe_id' => $this->employe()->id, 'type_remuneration_id' => $type->id,
            'date_debut' => '2026-11-01', 'montant' => 1000, 'statut' => 'a_valider',
        ]);
        $statut = fn (array $d) => $this->actingAs($super)->from(route('types-remuneration.index'))
            ->patch(route('types-remuneration.statut', [$type, 'desactiver']), $d);

        $statut([])->assertSessionHasErrors('date_effet');
        $this->assertSame(StatutTypeRemuneration::ACTIF, $type->fresh()->statut);

        $statut(['date_effet' => '2026-12-01'])->assertSessionHasNoErrors();

        $type->refresh();
        $this->assertSame(StatutTypeRemuneration::INACTIF, $type->statut);
        $this->assertSame('2026-12-01', $type->desactive_au->toDateString());
        $this->assertSame('2026-11-30', $enCours->fresh()->date_fin->toDateString());
        $this->assertStringContainsString('Désactivation', $enCours->fresh()->motif_fin);
        $this->assertSame(StatutAffectationRemuneration::ANNULEE, $future->fresh()->statut);
        $this->assertSame(StatutAffectationRemuneration::ANNULEE, $proposition->fresh()->statut);

        // Réactivation : même code, rien ne reprend tout seul.
        $this->actingAs($super)->patch(route('types-remuneration.statut', [$type, 'reactiver']))->assertSessionHasNoErrors();
        $this->assertNull($type->fresh()->desactive_au);
        $this->assertSame('2026-11-30', $enCours->fresh()->date_fin->toDateString());
    }

    public function test_une_desactivation_ne_peut_pas_prendre_effet_dans_un_mois_fige(): void
    {
        $this->figerOctobre();
        $super = $this->superAdmin();
        $type = $this->type();
        $this->affectationValidee($this->employe(), $type, ['date_debut' => '2026-09-01']);

        $this->actingAs($super)->from(route('types-remuneration.index'))
            ->patch(route('types-remuneration.statut', [$type, 'desactiver']), ['date_effet' => '2026-10-15'])
            ->assertSessionHasErrors('date_effet');
        $this->assertSame(StatutTypeRemuneration::ACTIF, $type->fresh()->statut);
    }

    public function test_un_type_attribue_ne_se_supprime_plus_et_ses_caracteristiques_sont_figees(): void
    {
        $super = $this->superAdmin();
        $type = $this->type();
        $this->affectationValidee($this->employe(), $type);

        $this->actingAs($super)->from(route('types-remuneration.index'))
            ->delete(route('types-remuneration.destroy', $type))->assertSessionHasErrors('type');
        $this->actingAs($super)->from(route('types-remuneration.index'))
            ->put(route('types-remuneration.update', $type), [
                'libelle' => $type->libelle, 'categorie' => 'indemnite', 'mode_calcul' => 'montant_fixe',
                'periodicite' => 'mensuelle', 'nature_montant' => 'fixe',
            ])->assertSessionHasErrors('categorie');
        $this->assertTrue(TypeRemuneration::whereKey($type->id)->exists());
    }

    // ── Cloisonnement ─────────────────────────────────────────────────────────

    public function test_l_organisation_est_cloisonnee_meme_pour_le_super_admin(): void
    {
        $autreOrg = Organization::factory()->create();
        Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Ailleurs', 'type' => 'depot']);
        $superAilleurs = $this->superAdmin($autreOrg);
        $employe = $this->employe();
        $type = $this->type();
        $affectation = $this->affectationValidee($employe, $type, ['date_fin_demandee' => '2026-11-30']);

        $this->affecter(['employe_id' => $employe->id, 'type_remuneration_id' => $this->type([], $autreOrg)->id, 'date_debut' => '2026-11-01', 'montant' => 1], $superAilleurs)
            ->assertSessionHasErrors('employe_id');
        $this->actingAs($superAilleurs)->patch(route('affectations-remuneration.valider', $affectation))->assertForbidden();
        $this->actingAs($superAilleurs)->getJson(route('affectations-remuneration.historique', $affectation))->assertForbidden();
        $this->actingAs($superAilleurs)->get(route('affectations-remuneration.index'))
            ->assertInertia(fn (Assert $page) => $page->has('affectations', 0));
        $this->assertNotNull($affectation->fresh()->date_fin_demandee);
    }

    public function test_un_utilisateur_limite_a_son_agence_ne_voit_ni_ne_modifie_les_autres_agences(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kindia', 'type' => 'depot']);
        $manager = $this->managerAgence($this->site, self::GESTION);
        $chezMoi = $this->employe();
        $ailleurs = $this->employe([], null, $autreSite);
        $type = $this->type();
        $this->affectationValidee($chezMoi, $type);
        $affectationAilleurs = $this->affectationValidee($ailleurs, $type);

        $this->actingAs($manager)->get(route('affectations-remuneration.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('affectations', 1)
                ->where('affectations.0.employe.id', $chezMoi->id)
                ->where('affectations.0.peut_agir', true));

        $this->affecter(['employe_id' => $ailleurs->id, 'type_remuneration_id' => $this->type()->id, 'date_debut' => '2026-11-01', 'montant' => 1], $manager)
            ->assertForbidden();
        $this->actingAs($manager)->post(route('affectations-remuneration.fin', $affectationAilleurs), ['date_fin' => '2026-11-30', 'motif' => 'x'])
            ->assertForbidden();
        $this->actingAs($manager)->getJson(route('affectations-remuneration.historique', $affectationAilleurs))->assertForbidden();
        $this->assertNull($affectationAilleurs->fresh()->date_fin_demandee);
    }

    public function test_sans_permission_ni_lecture_ni_ecriture(): void
    {
        $sans = $this->managerAgence($this->site, []);
        $affectation = $this->affectationValidee($this->employe(), $this->type());

        $this->actingAs($sans)->get(route('affectations-remuneration.index'))->assertForbidden();
        // Permission contrôlée avant les champs : pas de message de validation qui renseignerait.
        $this->actingAs($sans)->post(route('affectations-remuneration.store'), [])->assertForbidden();
        $this->actingAs($sans)->post(route('affectations-remuneration.fin', $affectation), [])->assertForbidden();
        $this->actingAs($sans)->patch(route('affectations-remuneration.annuler', $affectation))->assertForbidden();
        $this->actingAs($sans)->getJson(route('affectations-remuneration.historique', $affectation))->assertForbidden();
    }

    public function test_la_fiche_salarie_montre_les_primes_seulement_avec_la_permission(): void
    {
        foreach (['rh-employes.read'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo('rh-employes.read');
        $employe = $this->employe();
        $this->affectationValidee($employe, $this->type());

        $this->actingAs($this->user)->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->has('primes', 1)->where('primes.0.montant', 500000));

        $lecteur = $this->managerAgence($this->site, ['rh-employes.read']);
        $this->actingAs($lecteur)->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('primes', null));
    }

    // ── Liste, filtres, historique ────────────────────────────────────────────

    public function test_la_liste_filtre_par_salarie_type_categorie_et_periode(): void
    {
        $a = $this->employe();
        $b = $this->employe();
        $prime = $this->type();
        $avantage = $this->type(['categorie' => 'avantage_nature']);
        $this->affectationValidee($a, $prime, ['date_debut' => '2026-01-01', 'date_fin' => '2026-03-31']);
        $this->affectationValidee($a, $avantage, ['date_debut' => '2026-10-01']);
        $this->affectationValidee($b, $prime, ['date_debut' => '2026-10-01']);
        $liste = fn (array $filtres) => $this->actingAs($this->user)->get(route('affectations-remuneration.index', $filtres));

        $liste(['employe_id' => $a->id])->assertInertia(fn (Assert $p) => $p->has('affectations', 2));
        $liste(['type_remuneration_id' => $prime->id])->assertInertia(fn (Assert $p) => $p->has('affectations', 2));
        $liste(['categorie' => 'avantage_nature'])->assertInertia(fn (Assert $p) => $p->has('affectations', 1));
        $liste(['periode_du' => '2026-11-01', 'periode_au' => '2026-11-30'])->assertInertia(fn (Assert $p) => $p->has('affectations', 2));
        $liste(['etat' => 'terminee'])->assertInertia(fn (Assert $p) => $p->has('affectations', 1)->where('affectations.0.etat', 'terminee'));
        $liste(['site_ids' => [$this->site->id]])->assertInertia(fn (Assert $p) => $p->has('affectations', 3));
    }

    public function test_l_historique_retrace_chaque_operation(): void
    {
        $super = $this->superAdmin();
        $this->affecter(['employe_id' => $this->employe()->id, 'type_remuneration_id' => $this->type()->id, 'date_debut' => '2026-11-01', 'montant' => 1000]);
        $affectation = AffectationRemuneration::sole();
        $this->actingAs($super)->patch(route('affectations-remuneration.valider', $affectation));
        $this->actingAs($super)->post(route('affectations-remuneration.fin', $affectation), ['date_fin' => '2026-12-31', 'motif' => 'Fin de mission', 'valider' => true]);

        $this->actingAs($this->user)->getJson(route('affectations-remuneration.historique', $affectation))
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonFragment(['event_code' => 'ended'])
            ->assertJsonFragment(['module_label' => 'Primes des salariés']);
    }

    public function test_la_migration_de_rattrapage_accorde_les_permissions_aux_roles_existants(): void
    {
        foreach (['super_admin', 'admin_entreprise', 'comptable', 'manager'] as $nom) {
            Role::firstOrCreate(['name' => $nom, 'guard_name' => 'web']);
        }
        $migration = require database_path('migrations/2026_10_29_110100_backfill_permissions_affectations_transmissions_paie.php');
        $migration->up();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $super = Role::findByName('super_admin');
        $admin = Role::findByName('admin_entreprise');
        $this->assertTrue($super->hasPermissionTo('rh-affectations-remuneration.valider'));
        $this->assertTrue($super->hasPermissionTo('rh-transmissions-paie.transmettre'));
        $this->assertTrue($admin->hasPermissionTo('rh-affectations-remuneration.create'));
        $this->assertTrue($admin->hasPermissionTo('rh-transmissions-paie.preparer'));
        $this->assertFalse($admin->hasPermissionTo('rh-affectations-remuneration.valider'));
        $this->assertFalse($admin->hasPermissionTo('rh-transmissions-paie.valider'));
        $this->assertFalse($admin->hasPermissionTo('rh-transmissions-paie.transmettre'));
        $this->assertTrue(Role::findByName('comptable')->hasPermissionTo('rh-transmissions-paie.read'));
        $this->assertFalse(Role::findByName('manager')->hasPermissionTo('rh-affectations-remuneration.read'));
    }

    // ── Atomicité : une panne en cours d'opération ne laisse rien à moitié ────

    public function test_une_panne_pendant_la_desactivation_d_un_type_ne_change_aucune_affectation(): void
    {
        $super = $this->superAdmin();
        $type = $this->type();
        $premiere = $this->affectationValidee($this->employe(), $type, ['date_debut' => '2026-10-01']);
        $seconde = $this->affectationValidee($this->employe(), $type, ['date_debut' => '2026-10-01']);
        $this->simulerPanneDuJournal();

        try {
            app(TypeRemunerationService::class)->desactiver($type, $super, '2026-12-01');
            $this->fail('La désactivation aurait dû échouer.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(StatutTypeRemuneration::ACTIF, $type->fresh()->statut);
        $this->assertNull($type->fresh()->desactive_au);
        $this->assertNull($premiere->fresh()->date_fin);
        $this->assertNull($seconde->fresh()->date_fin);
    }

    public function test_une_panne_pendant_la_validation_d_un_changement_de_montant_ne_coupe_pas_l_ancien(): void
    {
        $super = $this->superAdmin();
        $initiale = $this->affectationValidee($this->employe(), $this->type(), ['date_debut' => '2026-10-01']);
        $this->actingAs($this->user)->post(route('affectations-remuneration.changement-montant', $initiale), ['date_effet' => '2026-12-01', 'montant' => 600000])
            ->assertSessionHasNoErrors();
        $nouvelle = AffectationRemuneration::where('remplace_affectation_id', $initiale->id)->sole();
        $this->simulerPanneDuJournal();

        try {
            app(AffectationRemunerationService::class)->valider($nouvelle, $super);
            $this->fail('La validation aurait dû échouer.');
        } catch (\RuntimeException) {
        }

        $this->assertNull($initiale->fresh()->date_fin);
        $this->assertSame(StatutAffectationRemuneration::A_VALIDER, $nouvelle->fresh()->statut);
    }
}
