<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employe;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Création d'un employé quand la personne existe déjà sous un autre rôle (utilisateur, client,
 * propriétaire, livreur...) — cf. docs/rh-employes-identite.md. Le téléphone normalisé est la
 * seule clé de rapprochement (Personne::resoudreOuCreer()).
 */
class EmployeCreationIdentiteExistanteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $rh;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt', 'type' => 'depot']);

        $permissions = ['rh-employes.read', 'rh-employes.create', 'rh-employes.update'];
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $this->rh = User::factory()->create(['organization_id' => $this->org->id]);
        $this->rh->assignRole('admin_entreprise');
        $this->rh->givePermissionTo($permissions);
        $this->rh->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function creerEmploye(array $donnees = []): TestResponse
    {
        return $this->actingAs($this->rh)->post(route('employes.store'), array_merge([
            'nom' => 'barry',
            'prenom' => 'alpha',
            'type_employe' => 'interne',
            'statut' => 'actif',
        ], $donnees));
    }

    private function personne(string $telephone, array $identite = []): Personne
    {
        return Personne::resoudreOuCreer($this->org->id, ['telephone' => $telephone, ...$identite]);
    }

    private function employesDe(Personne $personne): int
    {
        return Employe::where('personne_id', $personne->id)->count();
    }

    // ── Cas 6 : personne inconnue ─────────────────────────────────────────────

    public function test_personne_inconnue_cree_une_personne_et_un_employe_sans_compte_utilisateur(): void
    {
        $usersAvant = User::count();

        $this->creerEmploye(['telephone' => '+224620000001', 'email' => 'alpha@exemple.gn'])->assertRedirect();

        $personne = Personne::where('organization_id', $this->org->id)->where('telephone_normalise', '224620000001')->sole();
        $this->assertSame('BARRY', $personne->nom);
        $this->assertSame('Alpha', $personne->prenom);
        $this->assertSame(1, $this->employesDe($personne));
        $this->assertSame($usersAvant, User::count());
    }

    // ── Cas 1 : déjà utilisateur ──────────────────────────────────────────────

    public function test_utilisateur_existant_est_rattache_a_sa_personne_sans_nouveau_compte(): void
    {
        $personne = $this->personne('+224620000002', ['nom' => 'SOW', 'prenom' => 'Aissatou']);
        $compte = User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);
        $usersAvant = User::count();

        $this->creerEmploye(['telephone' => '+224620000002'])->assertRedirect();

        $employe = Employe::where('personne_id', $personne->id)->sole();
        $this->assertSame($usersAvant, User::count());
        $this->assertSame($compte->id, $employe->personne->user->id);
        $this->assertSame('Aissatou SOW', $employe->nom_complet);
    }

    // ── Cas 2 : déjà client ───────────────────────────────────────────────────

    public function test_client_existant_partage_la_personne_et_lidentite_employe_est_completee(): void
    {
        $personne = $this->personne('+224620000003', ['nom_complet' => 'Boutique Kaloum']);
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Boutique Kaloum',
            'telephone' => '+224620000003',
        ]);

        $this->creerEmploye(['telephone' => '+224620000003'])->assertRedirect();

        $employe = Employe::where('personne_id', $personne->id)->sole();
        $this->assertSame('BARRY', $employe->nom);
        $this->assertSame('Alpha', $employe->prenom);
        // Le client garde ses propres colonnes d'identité, jamais modifiées par la création RH.
        $this->assertSame('Boutique Kaloum', $client->fresh()->nom_complet);
        $this->assertSame(1, Personne::where('organization_id', $this->org->id)->where('telephone_normalise', '224620000003')->count());
    }

    // ── Cas 3 et 7 : déjà propriétaire, identité divergente ───────────────────

    public function test_proprietaire_existant_conserve_son_identite_meme_si_la_saisie_differe(): void
    {
        $personne = $this->personne('+224620000004', ['nom' => 'DIALLO', 'prenom' => 'Mamadou', 'email' => 'mamadou@exemple.gn']);
        Proprietaire::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);

        $this->creerEmploye(['telephone' => '+224620000004', 'email' => 'autre@exemple.gn'])->assertRedirect();

        $personne->refresh();
        $this->assertSame('DIALLO', $personne->nom);
        $this->assertSame('Mamadou', $personne->prenom);
        $this->assertSame('mamadou@exemple.gn', $personne->email);
        $this->assertSame(1, $this->employesDe($personne));
    }

    // ── Cas 4 : déjà livreur (créé au seul téléphone) ─────────────────────────

    public function test_livreur_sans_nom_connu_recoit_le_nom_saisi_pour_lemploye(): void
    {
        $personne = $this->personne('+224620000005');
        Livreur::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'nom_complet' => 'Baba Ousou', 'is_active' => true]);

        $this->creerEmploye(['telephone' => '+224620000005'])->assertRedirect();

        $employe = Employe::where('personne_id', $personne->id)->sole();
        $this->assertSame('Alpha BARRY', $employe->nom_complet);
        $this->assertSame('Baba Ousou', Livreur::where('personne_id', $personne->id)->sole()->nom_complet);
    }

    // ── Cas 5 : plusieurs profils simultanés ──────────────────────────────────

    public function test_personne_multi_profils_reste_une_seule_identite(): void
    {
        $personne = $this->personne('+224620000006', ['nom' => 'CAMARA', 'prenom' => 'Fanta']);
        User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);
        Proprietaire::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);
        Livreur::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'is_active' => true]);

        $this->creerEmploye(['telephone' => '+224 620 00 00 06'])->assertRedirect();

        $this->assertSame(1, Personne::where('organization_id', $this->org->id)->where('telephone_normalise', '224620000006')->count());
        $this->assertSame(1, $this->employesDe($personne));
    }

    // ── Doublons d'employé ────────────────────────────────────────────────────

    public function test_une_personne_deja_employee_est_rejetee(): void
    {
        $this->creerEmploye(['telephone' => '+224620000007'])->assertRedirect();

        $this->creerEmploye(['telephone' => '+224620000007', 'nom' => 'AUTRE', 'prenom' => 'Nom'])
            ->assertSessionHasErrors('telephone');

        $personne = Personne::where('organization_id', $this->org->id)->where('telephone_normalise', '224620000007')->sole();
        $this->assertSame(1, $this->employesDe($personne));
        $this->assertSame('BARRY', $personne->fresh()->nom);
    }

    public function test_un_ancien_employe_supprime_peut_etre_reembauche(): void
    {
        $this->creerEmploye(['telephone' => '+224620000008'])->assertRedirect();
        $ancien = Employe::whereHas('personne', fn ($q) => $q->where('telephone_normalise', '224620000008'))->sole();
        $ancien->delete();

        $this->creerEmploye(['telephone' => '+224620000008'])->assertRedirect();

        $this->assertSame(2, Employe::withTrashed()->where('personne_id', $ancien->personne_id)->count());
        $this->assertSame(1, Employe::where('personne_id', $ancien->personne_id)->count());
    }

    public function test_email_deja_utilise_par_un_employe_est_rejete(): void
    {
        $this->creerEmploye(['telephone' => '+224620000009', 'email' => 'rh@exemple.gn'])->assertRedirect();

        $this->creerEmploye(['telephone' => '+224620000010', 'email' => 'RH@exemple.gn'])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, Employe::where('organization_id', $this->org->id)->count());
    }

    // ── Isolation organisationnelle ───────────────────────────────────────────

    public function test_site_dune_autre_organisation_est_refuse_a_la_creation(): void
    {
        $autreSite = Site::create(['organization_id' => Organization::factory()->create()->id, 'nom' => 'Autre', 'type' => 'depot']);

        $this->creerEmploye(['telephone' => '+224620000011', 'site_id' => $autreSite->id])
            ->assertSessionHasErrors('site_id');

        $this->assertSame(0, Employe::where('organization_id', $this->org->id)->count());
        $this->assertSame(0, Personne::where('telephone_normalise', '224620000011')->count());
    }

    public function test_site_dune_autre_organisation_est_refuse_a_la_modification(): void
    {
        $this->creerEmploye(['telephone' => '+224620000012', 'site_id' => $this->site->id])->assertRedirect();
        $employe = Employe::where('organization_id', $this->org->id)->sole();
        $autreSite = Site::create(['organization_id' => Organization::factory()->create()->id, 'nom' => 'Autre', 'type' => 'depot']);

        $this->actingAs($this->rh)->put(route('employes.update', $employe), [
            'nom' => 'BARRY', 'prenom' => 'Alpha', 'type_employe' => 'interne', 'statut' => 'actif',
            'site_id' => $autreSite->id,
        ])->assertSessionHasErrors('site_id');

        $this->assertSame($this->site->id, $employe->fresh()->site_id);
    }
}
