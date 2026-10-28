<?php

namespace Tests\Feature;

use App\Models\Contrat;
use App\Models\Employe;
use App\Models\EmployeCoordonneesPaiement;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\ServiceRh;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dossier salarié (lot 1 RH) : champs facultatifs, données sensibles et salaires réservés à leur
 * permission, téléphone partagé protégé, liste limitée aux agences de l'utilisateur.
 */
class EmployeDossierTest extends TestCase
{
    use RefreshDatabase;

    private const CRUD = ['rh-employes.read', 'rh-employes.create', 'rh-employes.update', 'rh-employes.delete'];

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt', 'type' => 'depot']);
    }

    private function utilisateur(array $permissions, string $role = 'admin_entreprise', ?Site $site = null): User
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

    private function employe(?Site $site = null, array $attributs = []): Employe
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'DIALLO',
            'prenom' => 'Aïssatou',
            // Plage mobile guinéenne valide pour libphonenumber (toutes les plages 6x ne le sont pas).
            'telephone' => '+22462'.fake()->unique()->numerify('#######'),
        ]);

        return Employe::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'matricule' => fake()->unique()->numerify('######'),
            'type_employe' => 'interne',
            'statut' => 'actif',
            'site_id' => $site?->id,
            ...$attributs,
        ]);
    }

    private function payload(array $surcharges = []): array
    {
        return [
            'nom' => 'Diallo',
            'prenom' => 'aïssatou',
            'type_employe' => 'interne',
            'statut' => 'actif',
            ...$surcharges,
        ];
    }

    // ── Enregistrement du dossier ─────────────────────────────────────────────

    public function test_la_creation_enregistre_le_dossier_et_l_identite_civile(): void
    {
        $service = ServiceRh::create(['organization_id' => $this->org->id, 'libelle' => 'Logistique', 'code' => 'LOG']);

        $this->actingAs($this->utilisateur(self::CRUD))
            ->post(route('employes.store'), $this->payload([
                'service_rh_id' => $service->id,
                'date_embauche' => '2024-03-01',
                'date_naissance' => '1990-05-12',
                'lieu_naissance' => 'Conakry',
                'sexe' => 'F',
                'nationalite' => 'Guinéenne',
                'contact_urgence_nom' => 'Mamadou Diallo',
                'contact_urgence_lien' => 'Frère',
                'contact_urgence_telephone' => '+224620000000',
            ]))
            ->assertSessionHasNoErrors();

        $employe = Employe::with('personne')->firstOrFail();
        $this->assertSame($service->id, $employe->service_rh_id);
        $this->assertSame('2024-03-01', $employe->date_embauche->format('Y-m-d'));
        $this->assertSame('Mamadou Diallo', $employe->contact_urgence_nom);
        $this->assertSame('1990-05-12', $employe->personne->date_naissance->format('Y-m-d'));
        $this->assertSame('Conakry', $employe->personne->lieu_naissance);
        $this->assertSame('F', $employe->personne->sexe);
    }

    public function test_les_champs_du_dossier_sont_facultatifs(): void
    {
        $this->actingAs($this->utilisateur(self::CRUD))
            ->post(route('employes.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertNull(Employe::firstOrFail()->date_embauche);
    }

    public function test_un_service_d_une_autre_organisation_est_refuse(): void
    {
        $autreOrg = Organization::factory()->create();
        $service = ServiceRh::create(['organization_id' => $autreOrg->id, 'libelle' => 'Logistique', 'code' => 'LOG']);

        $this->actingAs($this->utilisateur(self::CRUD))
            ->post(route('employes.store'), $this->payload(['service_rh_id' => $service->id]))
            ->assertSessionHasErrors('service_rh_id');
    }

    public function test_la_date_de_sortie_ne_precede_pas_l_embauche(): void
    {
        $this->actingAs($this->utilisateur(self::CRUD))
            ->post(route('employes.store'), $this->payload([
                'date_embauche' => '2024-03-01',
                'date_sortie' => '2024-01-01',
            ]))
            ->assertSessionHasErrors('date_sortie');
    }

    // ── Données sensibles ─────────────────────────────────────────────────────

    public function test_les_donnees_sensibles_sont_enregistrees_et_le_numero_de_compte_chiffre(): void
    {
        $employe = $this->employe($this->site);
        $user = $this->utilisateur([...self::CRUD, 'rh-employes.donnees_sensibles']);

        $this->actingAs($user)
            ->put(route('employes.update', $employe), $this->payload([
                'telephone' => $employe->telephone,
                'site_id' => $this->site->id,
                'numero_cnss' => 'CNSS-123',
                'paiement_mode' => 'mobile_money',
                'paiement_etablissement' => 'Orange',
                'paiement_numero_compte' => '620123456',
                'paiement_titulaire' => 'Aïssatou Diallo',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('CNSS-123', $employe->fresh()->numero_cnss);
        $coordonnees = EmployeCoordonneesPaiement::where('employe_id', $employe->id)->firstOrFail();
        $this->assertSame('620123456', $coordonnees->numero_compte);
        $brut = DB::table('employe_coordonnees_paiement')->where('employe_id', $employe->id)->value('numero_compte');
        $this->assertNotSame('620123456', $brut);

        $this->actingAs($user)
            ->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page
                ->where('employe.donnees_sensibles.numero_cnss', 'CNSS-123')
                ->where('employe.donnees_sensibles.paiement_numero_compte', '620123456'));
    }

    public function test_sans_permission_les_donnees_sensibles_ne_sont_ni_envoyees_ni_modifiables(): void
    {
        $employe = $this->employe($this->site, ['numero_cnss' => 'CNSS-ORIGINE']);
        $user = $this->utilisateur(self::CRUD);

        $this->actingAs($user)
            ->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('employe.donnees_sensibles', null));

        $this->actingAs($user)
            ->put(route('employes.update', $employe), $this->payload([
                'telephone' => $employe->telephone,
                'site_id' => $this->site->id,
                'numero_cnss' => 'PIRATE',
                'paiement_mode' => 'virement',
                'paiement_numero_compte' => '000',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('CNSS-ORIGINE', $employe->fresh()->numero_cnss);
        $this->assertDatabaseMissing('employe_coordonnees_paiement', ['employe_id' => $employe->id]);
    }

    public function test_le_numero_cnss_n_apparait_jamais_dans_une_serialisation_brute(): void
    {
        $employe = $this->employe(null, ['numero_cnss' => 'CNSS-123']);

        $this->assertArrayNotHasKey('numero_cnss', $employe->toArray());
    }

    // ── Salaires ──────────────────────────────────────────────────────────────

    public function test_le_salaire_n_est_envoye_qu_avec_rh_remunerations_read(): void
    {
        $employe = $this->employe($this->site);
        Contrat::create([
            'organization_id' => $this->org->id, 'employe_id' => $employe->id, 'type_contrat' => 'cdi',
            'date_debut' => '2024-01-01', 'salaire_base' => 2_500_000, 'statut_contrat' => 'actif',
        ]);

        $this->actingAs($this->utilisateur(self::CRUD))
            ->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('employe.contrats.0.salaire_base', null));

        $this->actingAs($this->utilisateur([...self::CRUD, 'rh-remunerations.read']))
            ->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('employe.contrats.0.salaire_base', fn ($v) => (float) $v === 2_500_000.0));
    }

    // ── Téléphone partagé (M4) ────────────────────────────────────────────────

    public function test_le_telephone_d_une_personne_ayant_un_compte_ne_peut_pas_etre_efface(): void
    {
        $employe = $this->employe($this->site);
        User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $employe->personne_id]);

        $this->actingAs($this->utilisateur(self::CRUD))
            ->put(route('employes.update', $employe), $this->payload(['telephone' => '', 'site_id' => $this->site->id]))
            ->assertSessionHasErrors('telephone');

        $this->assertNotNull($employe->personne->fresh()->telephone);
    }

    public function test_le_telephone_d_un_employe_sans_autre_role_peut_etre_efface(): void
    {
        $employe = $this->employe($this->site);

        $this->actingAs($this->utilisateur(self::CRUD))
            ->put(route('employes.update', $employe), $this->payload(['telephone' => '', 'site_id' => $this->site->id]))
            ->assertSessionHasNoErrors();

        $this->assertNull($employe->personne->fresh()->telephone);
    }

    // ── Cloisonnement par agence ──────────────────────────────────────────────

    public function test_la_liste_est_limitee_aux_agences_de_l_utilisateur(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence B', 'type' => 'depot']);
        $employeA = $this->employe($this->site);
        $this->employe($autreSite);
        $this->employe(null);

        $this->actingAs($this->utilisateur(['rh-employes.read'], 'manager'))
            ->get(route('employes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('employes', 1)
                ->where('employes.0.id', $employeA->id));
    }

    public function test_un_administrateur_voit_toutes_les_agences_et_peut_filtrer(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence B', 'type' => 'depot']);
        $this->employe($this->site);
        $employeB = $this->employe($autreSite);
        $admin = $this->utilisateur(['rh-employes.read']);

        $this->actingAs($admin)
            ->get(route('employes.index'))
            ->assertInertia(fn (Assert $page) => $page->has('employes', 2)->has('sites', 2));

        $this->actingAs($admin)
            ->get(route('employes.index', ['site_ids' => [$autreSite->id]]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('employes', 1)
                ->where('employes.0.id', $employeB->id));
    }
}
