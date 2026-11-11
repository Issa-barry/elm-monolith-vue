<?php

namespace Tests\Feature\Contacts;

use App\Enums\ClientType;
use App\Enums\PrestataireType;
use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\ContactsFusion;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Services\Contacts\Fusion\FusionContacts;
use App\Support\Contacts\MessagesContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lot 9b — écrans et points d'entrée de la fusion (permission F4, organisation, confirmation,
 * redirection de la fiche absorbée, historique, annulation) et numéro secondaire (décision 3).
 */
class FusionContactsHttpTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['contacts.read', 'contacts.create', 'contacts.fusionner', 'clients.read', 'livreurs.read', 'prestataires.read', 'prestataires.create', 'users.update'];

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        foreach ([ModuleFeature::ACHATS, ModuleFeature::VEHICULES, ModuleFeature::PRESTATAIRES] as $module) {
            Feature::for($this->org)->activate($module);
        }
    }

    private function acteur(array $permissions = self::PERMISSIONS, ?Organization $org = null): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $org ??= $this->org;
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Agence '.$user->id, 'type' => 'depot', 'localisation' => 'Conakry']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    /** @return array{Personne, Personne} A (livreur) et B (client), fusionnables. */
    private function deuxFiches(): array
    {
        $a = Personne::factory()->create(['organization_id' => $this->org->id, 'nom_complet' => 'Rayanatou Sylla', 'telephone' => '+224624000001', 'telephone_secondaire' => null]);
        $b = Personne::factory()->create(['organization_id' => $this->org->id, 'nom_complet' => 'Rayanatou', 'telephone' => '+224624000002', 'telephone_secondaire' => null]);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        DB::table('livreurs')->where('id', $livreur->id)->update(['personne_id' => $a->id]);
        Client::create(['organization_id' => $this->org->id, 'personne_id' => $b->id, 'type' => ClientType::EXTERNE->value, 'nom_complet' => 'Rayanatou', 'telephone' => '+224624000002']);

        return [$a, $b];
    }

    private function fusionner(User $acteur, Personne $a, Personne $b): ContactsFusion
    {
        $simulation = app(FusionContacts::class)->simuler($acteur, 'personne', $a->id, $b->id);

        return app(FusionContacts::class)->executer($acteur, 'personne', $a->id, $b->id, $simulation['choix'], ['fusion' => true], $simulation['empreinte'], (string) Str::uuid());
    }

    public function test_sans_la_permission_fusionner_tout_est_refuse(): void
    {
        [$a, $b] = $this->deuxFiches();
        $c = Personne::factory()->create(['organization_id' => $this->org->id, 'telephone_secondaire' => null]);
        Client::create(['organization_id' => $this->org->id, 'personne_id' => $c->id, 'type' => ClientType::EXTERNE->value, 'nom_complet' => 'Autre', 'telephone' => '+224624000099']);
        $fusion = $this->fusionner($this->acteur(), $a, $c);
        $sansDroit = $this->acteur(array_diff(self::PERMISSIONS, ['contacts.fusionner']));

        $this->actingAs($sansDroit)->get(route('contacts.fusion.create', ['type' => 'personne', 'fiche' => $a->id]))->assertForbidden();
        $this->actingAs($sansDroit)->post(route('contacts.fusions.store'), ['type' => 'personne', 'fiche_a' => $a->id, 'fiche_b' => $b->id, 'empreinte' => str_repeat('a', 64), 'jeton' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($sansDroit)->get(route('contacts.fusions.index'))->assertForbidden();
        $this->actingAs($sansDroit)->get(route('contacts.fusions.show', $fusion))->assertForbidden();
        $this->actingAs($sansDroit)->post(route('contacts.fusions.annuler', $fusion))->assertForbidden();
        $this->assertSame(ContactsFusion::EXECUTEE, $fusion->fresh()->statut);
        $this->assertFalse($a->fresh()->trashed());
    }

    public function test_ecran_recherche_puis_simulation(): void
    {
        [$a, $b] = $this->deuxFiches();
        $acteur = $this->acteur();

        $this->actingAs($acteur)->get(route('contacts.fusion.create', ['type' => 'personne', 'fiche' => $a->id, 'recherche' => 'Rayanatou']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Contacts/Fusion')
                ->where('fiche_a.id', $a->id)
                ->where('candidats.0.id', $b->id)
                ->has('candidats', 1)
                ->where('simulation', null));

        $this->actingAs($acteur)->get(route('contacts.fusion.create', ['type' => 'personne', 'fiche' => $a->id, 'b' => $b->id, 'choix' => ['nom_complet' => 'b']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('simulation.b.id', $b->id)
                ->where('simulation.choix.nom_complet', 'b')
                ->where('simulation.blocages', [])
                ->has('jeton'));
        $this->assertFalse($b->fresh()->trashed());
    }

    public function test_confirmation_exigee_puis_fusion_et_redirection_de_b(): void
    {
        [$a, $b] = $this->deuxFiches();
        $acteur = $this->acteur();
        $simulation = app(FusionContacts::class)->simuler($acteur, 'personne', $a->id, $b->id);
        $donnees = ['type' => 'personne', 'fiche_a' => $a->id, 'fiche_b' => $b->id, 'choix' => $simulation['choix'], 'empreinte' => $simulation['empreinte'], 'jeton' => (string) Str::uuid()];

        $this->actingAs($acteur)->post(route('contacts.fusions.store'), [...$donnees, 'confirmations' => ['fusion' => '0']])
            ->assertSessionHasErrors('confirmations.fusion');
        $this->assertFalse($b->fresh()->trashed());

        $this->actingAs($acteur)->post(route('contacts.fusions.store'), [...$donnees, 'confirmations' => ['fusion' => '1']])
            ->assertRedirect("/backoffice/contacts/personnes/{$a->id}")
            ->assertSessionHas('success');
        $this->assertTrue(Personne::withTrashed()->findOrFail($b->id)->trashed());

        // L'adresse de B mène à A.
        $this->actingAs($acteur)->get(route('contacts.personnes.show', $b->id))
            ->assertRedirect("/backoffice/contacts/personnes/{$a->id}");
    }

    public function test_une_fiche_archivee_sans_fusion_reste_introuvable(): void
    {
        [$a] = $this->deuxFiches();
        $a->delete();

        $this->actingAs($this->acteur())->get(route('contacts.personnes.show', $a->id))->assertNotFound();
    }

    public function test_historique_detail_et_annulation(): void
    {
        [$a, $b] = $this->deuxFiches();
        $acteur = $this->acteur();
        $fusion = $this->fusionner($acteur, $a, $b);

        $this->actingAs($acteur)->get(route('contacts.fusions.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Contacts/Fusions/Index')
                ->where('fusions.data.0.id', $fusion->id)
                ->where('fusions.data.0.statut', ContactsFusion::EXECUTEE));

        $this->actingAs($acteur)->get(route('contacts.fusions.show', $fusion))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Contacts/Fusions/Show')
                ->where('analyse_annulation.possible', true)
                ->where('analyse_annulation.interventions', []));

        $this->actingAs($acteur)->post(route('contacts.fusions.annuler', $fusion))
            ->assertRedirect(route('contacts.fusions.show', $fusion));
        $this->assertSame(ContactsFusion::ANNULEE, $fusion->fresh()->statut);
        $this->assertFalse($b->fresh()->trashed());
    }

    public function test_une_fusion_d_une_autre_organisation_est_introuvable(): void
    {
        [$a, $b] = $this->deuxFiches();
        $fusion = $this->fusionner($this->acteur(), $a, $b);
        $ailleurs = Organization::factory()->create();
        $etranger = $this->acteur(self::PERMISSIONS, $ailleurs);

        $this->actingAs($etranger)->get(route('contacts.fusions.show', $fusion))->assertNotFound();
        $this->actingAs($etranger)->post(route('contacts.fusions.annuler', $fusion))->assertNotFound();
        $this->actingAs($etranger)->get(route('contacts.fusion.create', ['type' => 'personne', 'fiche' => $a->id]))->assertNotFound();
        $this->assertSame(ContactsFusion::EXECUTEE, $fusion->fresh()->statut);
    }

    // ── Décision 3 : numéro secondaire ──────────────────────────────────────

    public function test_numero_secondaire_reconnu_sans_creation_ni_rattachement(): void
    {
        [$a] = $this->deuxFiches();
        $a->update(['telephone_secondaire' => '+224 622 10 00 01']);
        $acteur = $this->acteur();

        $this->assertSame('224622100001', $a->fresh()->telephone_secondaire_normalise);
        $reponse = $this->actingAs($acteur)->getJson(route('contacts.recherche-telephone', ['type' => 'personne', 'telephone' => '+224622100001']))->assertOk();
        $this->assertSame('secondaire', $reponse->json('statut'));
        $this->assertSame($a->id, $reponse->json('fiche.id'));
        $this->assertNull($reponse->json('fiche.fonctions_ajoutables'));

        $this->actingAs($acteur)->post(route('contacts.personnes.store'), [
            'prenom' => 'mamadou', 'nom' => 'diallo', 'telephone' => '+224622100001', 'ville' => 'conakry',
            'fonction' => 'prestataire', 'type' => PrestataireType::MECANICIEN->value,
        ])->assertSessionHasErrors(['telephone' => MessagesContacts::NUMERO_SECONDAIRE_CONNU]);
        $this->assertSame(0, Personne::where('telephone_normalise', '224622100001')->count());
    }
}
