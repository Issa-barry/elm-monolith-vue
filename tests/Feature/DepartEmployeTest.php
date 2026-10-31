<?php

namespace Tests\Feature;

use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\PaiePeriode;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Départ d'un salarié (docs/rh-depart-salarie.md) : seul chemin vers « Sorti », contrat actif
 * terminé à la date de sortie, annulation qui rétablit tout, mois de paie figés protégés, accès
 * (permissions, organisation, agence).
 */
class DepartEmployeTest extends TestCase
{
    use RefreshDatabase;

    private const DROITS = ['rh-employes.read', 'rh-employes.update', 'rh-contrats.update'];

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function utilisateur(array $permissions = self::DROITS, string $role = 'admin_entreprise', ?Site $site = null): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        $user->sites()->attach(($site ?? $this->site)->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function employe(array $attributs = [], ?Organization $org = null, ?Site $site = null): Employe
    {
        $orgId = ($org ?? $this->org)->id;
        $personne = Personne::create(['organization_id' => $orgId, 'nom' => 'SOW', 'prenom' => 'Mamadou',
            'telephone' => '+22462'.fake()->unique()->numerify('#######')]);

        return Employe::create([
            'organization_id' => $orgId, 'personne_id' => $personne->id, 'matricule' => fake()->unique()->numerify('######'),
            'type_employe' => 'interne', 'statut' => 'actif', 'site_id' => ($site ?? $this->site)->id,
            'date_embauche' => '2025-01-15', ...$attributs,
        ]);
    }

    private function contrat(Employe $employe, array $attributs = []): Contrat
    {
        return Contrat::create([
            'organization_id' => $employe->organization_id, 'employe_id' => $employe->id, 'type_contrat' => 'cdi',
            'date_debut' => '2025-01-15', 'salaire_base' => 750000, 'statut_contrat' => 'actif', ...$attributs,
        ]);
    }

    private function partir(User $user, Employe $employe, string $date = '2026-10-15', string $motif = 'Démission')
    {
        return $this->actingAs($user)->post(route('employes.depart.store', $employe), ['date_sortie' => $date, 'motif_sortie' => $motif]);
    }

    public function test_le_depart_passe_le_salarie_en_sorti_et_termine_son_cdi(): void
    {
        $employe = $this->employe();
        $contrat = $this->contrat($employe);

        $this->partir($this->utilisateur(), $employe)->assertSessionHasNoErrors()->assertRedirect();

        $employe->refresh();
        $this->assertSame('sorti', $employe->statut->value);
        $this->assertSame('2026-10-15', $employe->date_sortie->toDateString());
        $this->assertSame('Démission', $employe->motif_sortie);
        $contrat->refresh();
        $this->assertSame('termine', $contrat->statut_contrat->value);
        $this->assertSame('2026-10-15', $contrat->date_fin->toDateString());
        $this->assertTrue($contrat->termine_par_depart);
        $this->assertNull($contrat->date_fin_avant_depart);
    }

    public function test_un_cdd_est_ecourte_et_retrouve_sa_fin_prevue_a_l_annulation(): void
    {
        $employe = $this->employe();
        $contrat = $this->contrat($employe, ['type_contrat' => 'cdd', 'date_fin' => '2027-01-14']);
        $user = $this->utilisateur();

        $this->partir($user, $employe)->assertSessionHasNoErrors();
        $this->assertSame('2026-10-15', $contrat->fresh()->date_fin->toDateString());
        $this->assertSame('2027-01-14', $contrat->fresh()->date_fin_avant_depart->toDateString());

        $this->actingAs($user)->delete(route('employes.depart.destroy', $employe))->assertSessionHasNoErrors();

        $employe->refresh();
        $contrat->refresh();
        $this->assertSame('actif', $employe->statut->value);
        $this->assertNull($employe->date_sortie);
        $this->assertNull($employe->motif_sortie);
        $this->assertSame('actif', $contrat->statut_contrat->value);
        $this->assertSame('2027-01-14', $contrat->date_fin->toDateString());
        $this->assertFalse($contrat->termine_par_depart);
        $this->assertNull($contrat->date_fin_avant_depart);
    }

    public function test_l_annulation_d_un_cdi_lui_rend_une_duree_indeterminee(): void
    {
        $employe = $this->employe();
        $contrat = $this->contrat($employe);
        $user = $this->utilisateur();

        $this->partir($user, $employe)->assertSessionHasNoErrors();
        $this->actingAs($user)->delete(route('employes.depart.destroy', $employe))->assertSessionHasNoErrors();

        $this->assertNull($contrat->fresh()->date_fin);
        $this->assertSame('actif', $contrat->fresh()->statut_contrat->value);
    }

    public function test_un_salarie_sans_contrat_peut_partir(): void
    {
        $employe = $this->employe();

        $this->partir($this->utilisateur(), $employe)->assertSessionHasNoErrors();

        $this->assertSame('sorti', $employe->fresh()->statut->value);
    }

    public function test_les_dates_incoherentes_sont_refusees(): void
    {
        $employe = $this->employe();
        $this->contrat($employe, ['date_debut' => '2026-03-01']);
        $user = $this->utilisateur();

        $this->partir($user, $employe, '2026-10-21')->assertSessionHasErrors('date_sortie');
        $this->partir($user, $employe, '2024-12-31')->assertSessionHasErrors(['date_sortie' => 'La date de sortie ne peut pas précéder la date d’embauche (15/01/2025).']);
        $this->partir($user, $employe, '2026-02-15')->assertSessionHasErrors(['date_sortie' => 'La date de sortie précède le début du contrat en cours (01/03/2026).']);
        $this->partir($user, $employe, '2026-10-15', '  ')->assertSessionHasErrors('motif_sortie');

        $this->assertSame('actif', $employe->fresh()->statut->value);
    }

    public function test_un_depart_deja_enregistre_ne_se_double_pas(): void
    {
        $employe = $this->employe();
        $user = $this->utilisateur();

        $this->partir($user, $employe)->assertSessionHasNoErrors();
        $this->partir($user, $employe, '2026-10-10')->assertSessionHasErrors(['date_sortie' => 'Le départ de ce salarié est déjà enregistré.']);

        $this->assertSame('2026-10-15', $employe->fresh()->date_sortie->toDateString());
    }

    public function test_un_mois_de_paie_fige_protege_le_depart_et_son_annulation(): void
    {
        $employe = $this->employe();
        $contrat = $this->contrat($employe);
        $user = $this->utilisateur();
        $this->partir($user, $employe, '2026-09-20')->assertSessionHasNoErrors();

        PaiePeriode::create(['organization_id' => $this->org->id, 'annee' => 2026, 'mois' => 9, 'statut' => 'valide_rh']);

        $this->actingAs($user)->delete(route('employes.depart.destroy', $employe))
            ->assertSessionHasErrors(['depart' => 'La paie du mois de sortie est déjà figée : ce départ ne peut plus être annulé.']);
        $this->assertSame('sorti', $employe->fresh()->statut->value);
        $this->assertSame('termine', $contrat->fresh()->statut_contrat->value);

        $autre = $this->employe();
        $this->partir($user, $autre, '2026-09-30')->assertSessionHasErrors('date_sortie');
        $this->partir($user, $autre, '2026-10-01')->assertSessionHasNoErrors();
    }

    public function test_l_annulation_est_refusee_si_un_autre_contrat_est_actif(): void
    {
        $employe = $this->employe();
        $this->contrat($employe, ['date_debut' => '2025-01-15']);
        $user = $this->utilisateur();
        $this->partir($user, $employe, '2026-06-30')->assertSessionHasNoErrors();
        $this->contrat($employe, ['date_debut' => '2026-07-01']);

        $this->actingAs($user)->delete(route('employes.depart.destroy', $employe))->assertSessionHasErrors('depart');

        $this->assertSame('sorti', $employe->fresh()->statut->value);
    }

    public function test_le_formulaire_ne_rend_pas_actif_un_salarie_sorti(): void
    {
        $employe = $this->employe();
        $user = $this->utilisateur();
        $this->partir($user, $employe)->assertSessionHasNoErrors();

        $this->actingAs($user)->put(route('employes.update', $employe), [
            'nom' => 'SOW', 'prenom' => 'Mamadou', 'type_employe' => 'interne', 'statut' => 'actif',
        ])->assertSessionHasNoErrors();

        $employe->refresh();
        $this->assertSame('sorti', $employe->statut->value);
        $this->assertSame('2026-10-15', $employe->date_sortie->toDateString());
        $this->assertSame('Démission', $employe->motif_sortie);
    }

    public function test_l_acces_exige_les_droits_l_organisation_et_l_agence(): void
    {
        $employe = $this->employe();

        $this->partir($this->utilisateur(['rh-employes.read', 'rh-employes.update']), $employe)->assertForbidden();

        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Kaloum', 'type' => 'depot']);
        $this->partir($this->utilisateur(), $this->employe([], $autreOrg, $autreSite))->assertForbidden();

        $cba = Site::create(['organization_id' => $this->org->id, 'nom' => 'CBA', 'type' => 'depot']);
        $manager = $this->utilisateur(self::DROITS, 'manager', $cba);
        $this->partir($manager, $employe)->assertForbidden();
        $this->actingAs($manager)->delete(route('employes.depart.destroy', $employe))->assertForbidden();

        $this->assertSame('actif', $employe->fresh()->statut->value);
        $this->partir($this->utilisateur(self::DROITS, 'manager'), $employe)->assertSessionHasNoErrors();
    }

    public function test_aucun_contrat_ne_peut_etre_cree_ou_reactive_pour_un_salarie_sorti(): void
    {
        $employe = $this->employe();
        $ancien = $this->contrat($employe);
        $user = $this->utilisateur([...self::DROITS, 'rh-contrats.read', 'rh-contrats.create']);
        $this->partir($user, $employe)->assertSessionHasNoErrors();

        $this->actingAs($user)->post(route('contrats.store'), [
            'employe_id' => $employe->id, 'type_contrat' => 'cdi', 'date_debut' => '2026-10-19',
        ])->assertSessionHasErrors('employe_id');
        $this->assertSame(1, Contrat::where('employe_id', $employe->id)->count());

        // Rendre « actif » le contrat terminé par le départ reviendrait à une réembauche implicite.
        $this->actingAs($user)->put(route('contrats.update', $ancien), [
            'employe_id' => $employe->id, 'type_contrat' => 'cdi', 'date_debut' => '2025-01-15', 'statut_contrat' => 'actif',
        ])->assertSessionHasErrors('employe_id');
        $this->assertSame('termine', $ancien->fresh()->statut_contrat->value);
        $this->assertSame('sorti', $employe->fresh()->statut->value);

        // Le salarié sorti n'est pas proposé dans le formulaire de création.
        $this->actingAs($user)->get(route('contrats.create'))
            ->assertInertia(fn (Assert $page) => $page->where('employes', fn ($e) => ! collect($e)->contains('value', $employe->id)));
    }

    public function test_les_contrats_successifs_d_un_salarie_actif_restent_possibles(): void
    {
        $employe = $this->employe();
        $this->contrat($employe, ['type_contrat' => 'cdd', 'date_fin' => '2026-06-30', 'statut_contrat' => 'termine']);
        $user = $this->utilisateur([...self::DROITS, 'rh-contrats.read', 'rh-contrats.create']);

        $this->actingAs($user)->post(route('contrats.store'), [
            'employe_id' => $employe->id, 'type_contrat' => 'cdi', 'date_debut' => '2026-07-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Contrat::where('employe_id', $employe->id)->count());
    }

    public function test_la_fiche_n_affiche_le_bouton_qu_avec_le_droit_reel(): void
    {
        $employe = $this->employe();

        $this->actingAs($this->utilisateur())->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('employe.peut_gerer_depart', true));
        $this->actingAs($this->utilisateur(['rh-employes.read', 'rh-employes.update']))->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('employe.peut_gerer_depart', false));
    }
}
