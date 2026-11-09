<?php

namespace Tests\Feature\Contacts;

use App\Enums\ClientType;
use App\Enums\PrestataireType;
use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultation du référentiel Contacts (ADR 0060, lot 2, CONTACT-013 à CONTACT-016) : listes,
 * fiches et recherche en lecture seule, avec la visibilité décidée côté serveur.
 */
class ConsultationContactsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $siteA;

    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
        Feature::for($this->org)->activate(ModuleFeature::PRESTATAIRES);
        $this->siteA = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence A', 'type' => 'depot', 'localisation' => 'Conakry']);
        $this->siteB = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence B', 'type' => 'depot', 'localisation' => 'Kindia']);
    }

    /** Membre du personnel d'une seule agence, sans vision toutes agences. */
    private function lecteur(array $permissions, ?Site $site = null, string $role = 'manager', ?Organization $org = null): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $org ??= $this->org;
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);
        $siteId = $site?->id ?? ($org->is($this->org) ? $this->siteA->id : Site::create(['organization_id' => $org->id, 'nom' => 'Siège', 'type' => 'depot', 'localisation' => 'Conakry'])->id);
        $user->sites()->attach($siteId, ['role' => 'employe', 'is_default' => true]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function toutesAgences(User $user): User
    {
        Permission::firstOrCreate(['name' => User::PERMISSION_LECTURE_TOUTES_AGENCES, 'guard_name' => 'web']);
        $user->givePermissionTo(User::PERMISSION_LECTURE_TOUTES_AGENCES);

        return $user;
    }

    private function personne(array $attributs = []): Personne
    {
        return Personne::factory()->create(['organization_id' => $this->org->id, ...$attributs]);
    }

    private function client(Personne $p, ClientType $type = ClientType::EXTERNE): Client
    {
        return Client::create(['organization_id' => $this->org->id, 'personne_id' => $p->id, 'nom_complet' => $p->nom_complet, 'telephone' => $p->telephone, 'type' => $type->value]);
    }

    // Création directe : la factory Parrain remplace la personne qu'on lui passe.
    private function parrain(Personne $p, ?Site $siteDuVehicule = null): Parrain
    {
        $parrain = Parrain::create(['organization_id' => $this->org->id, 'personne_id' => $p->id, 'is_active' => true]);
        if ($siteDuVehicule) {
            Vehicule::factory()->create(['organization_id' => $this->org->id, 'site_id' => $siteDuVehicule->id, 'parrain_id' => $parrain->id]);
        }

        return $parrain;
    }

    private function idsListe(User $user, string $route, array $params = [], string $cle = 'personnes'): array
    {
        $ids = [];
        $this->actingAs($user)->get(route($route, $params))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$ids, $cle) {
                $ids = collect($page->toArray()['props'][$cle]['data'])->pluck('id')->all();
            });

        return $ids;
    }

    private function rechercher(User $user, string $terme): array
    {
        Sanctum::actingAs($user, ['*']);

        return $this->getJson(route('api.search.global', ['q' => $terme]))->assertOk()->json('results');
    }

    // ── Liste et badges ─────────────────────────────────────────────────────

    public function test_une_personne_a_trois_fonctions_apparait_une_fois_avec_trois_badges(): void
    {
        $p = $this->personne(['nom_complet' => 'Fatou Bah']);
        $client = $this->client($p, ClientType::GROSSISTE);
        $fournisseur = Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $p->id]);
        $proprietaire = Proprietaire::create(['organization_id' => $this->org->id, 'personne_id' => $p->id, 'type' => 'personne_physique', 'is_active' => true]);
        $user = $this->lecteur(['contacts.read', 'clients.read', 'fournisseurs.read', 'proprietaires.read']);

        $this->actingAs($user)->get(route('contacts.personnes.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Contacts/Personnes/Index')
                ->where('personnes.total', 1)
                ->where('personnes.data.0.id', $p->id)
                ->where('personnes.data.0.nom', 'Fatou Bah')
                ->has('personnes.data.0.fonctions', 3)
                ->where('personnes.data.0.fonctions.0.id', $client->id)
                ->where('personnes.data.0.fonctions.0.sous_categorie_libelle', 'Grossiste')
                ->where('personnes.data.0.fonctions.1.id', $fournisseur->id)
                ->where('personnes.data.0.fonctions.2.id', $proprietaire->id));
    }

    public function test_une_personne_sans_fonction_n_apparait_jamais(): void
    {
        $sansFonction = $this->personne(['nom_complet' => 'Compte Seul']);
        $user = $this->toutesAgences($this->lecteur(['contacts.read', 'clients.read', 'parrains.read']));

        $this->assertSame([], $this->idsListe($user, 'contacts.personnes.index'));
        $this->actingAs($user)->get(route('contacts.personnes.show', $sansFonction))->assertNotFound();
        $this->assertSame([], $this->rechercher($user, 'Compte Seul')['contacts']['items']);
    }

    public function test_les_filtres_fonction_sous_categorie_et_recherche(): void
    {
        $grossiste = $this->client($this->personne(['nom_complet' => 'Awa Grossiste', 'telephone' => '+224622111111']), ClientType::GROSSISTE)->personne;
        $externe = $this->client($this->personne(['nom_complet' => 'Binta Externe', 'ville' => 'Kindia']))->personne;
        $proprio = $this->personne(['nom_complet' => 'Chérif Propriétaire']);
        Proprietaire::create(['organization_id' => $this->org->id, 'personne_id' => $proprio->id, 'type' => 'personne_physique', 'is_active' => true]);
        $user = $this->lecteur(['contacts.read', 'clients.read', 'proprietaires.read']);

        $this->assertEqualsCanonicalizing([$grossiste->id, $externe->id], $this->idsListe($user, 'contacts.personnes.index', ['fonction' => 'client']));
        $this->assertSame([$grossiste->id], $this->idsListe($user, 'contacts.personnes.index', ['sous_categorie' => 'client:grossiste']));
        $this->assertSame([$grossiste->id], $this->idsListe($user, 'contacts.personnes.index', ['recherche' => '622 111']));
        $this->assertSame([$externe->id], $this->idsListe($user, 'contacts.personnes.index', ['ville' => 'kindia']));
        $this->assertSame([$proprio->id], $this->idsListe($user, 'contacts.personnes.index', ['recherche' => 'Chérif']));
    }

    public function test_la_liste_est_paginee_cote_serveur(): void
    {
        foreach (range(1, 27) as $i) {
            $this->client($this->personne(['nom_complet' => sprintf('Client %02d', $i)]));
        }
        $user = $this->lecteur(['contacts.read', 'clients.read']);

        $this->actingAs($user)->get(route('contacts.personnes.index', ['page' => 2]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('personnes.total', 27)
                ->where('personnes.current_page', 2)
                ->has('personnes.data', 2));
    }

    public function test_un_livreur_sans_nom_civil_s_affiche_par_sa_designation(): void
    {
        $p = $this->personne(['nom' => null, 'prenom' => null, 'nom_complet' => null]);
        Livreur::create(['organization_id' => $this->org->id, 'personne_id' => $p->id, 'nom_complet' => 'Chauffeur-1 Baba Ousou', 'is_active' => true]);
        $user = $this->lecteur(['contacts.read', 'livreurs.read']);

        $this->actingAs($user)->get(route('contacts.personnes.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('personnes.data.0.nom', 'Chauffeur-1 Baba Ousou'));
        $this->assertSame([$p->id], $this->idsListe($user, 'contacts.personnes.index', ['recherche' => 'Baba Ousou']));
    }

    // ── Fonctions non autorisées ────────────────────────────────────────────

    public function test_une_fonction_sans_permission_n_apparait_ni_en_badge_ni_en_filtre_ni_dans_les_donnees(): void
    {
        $p = $this->personne(['nom_complet' => 'Fatou Bah']);
        $this->client($p);
        $fournisseur = Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $p->id]);
        $user = $this->lecteur(['contacts.read', 'clients.read']);

        $this->actingAs($user)->get(route('contacts.personnes.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('personnes.data.0.fonctions', 1)
                ->where('personnes.data.0.fonctions.0.fonction', 'client')
                // Lot 7b : une entreprise cliente est visible avec clients.read, d'où le filtre Interlocuteur.
                ->where('options.fonctions', [['value' => 'client', 'label' => 'Client'], ['value' => 'interlocuteur', 'label' => 'Interlocuteur']])
                ->where('options.sous_categories', fn ($options) => collect($options)->every(fn ($o) => str_starts_with($o['value'], 'client:'))));

        $this->actingAs($user)->get(route('contacts.personnes.show', $p))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('fonctions', 1)
                ->where('fonctions.0.fonction', 'client'));

        $json = $this->actingAs($user)->get(route('contacts.personnes.show', $p))->getContent();
        $this->assertStringNotContainsString($fournisseur->id, $json);

        $this->actingAs($user)->get(route('contacts.personnes.index', ['fonction' => 'fournisseur']))
            ->assertSessionHasErrors('fonction');
    }

    public function test_une_fonction_dont_le_module_est_inactif_est_masquee(): void
    {
        $p = $this->personne();
        Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $p->id, 'type' => PrestataireType::MECANICIEN->value]);
        Feature::for($this->org)->deactivate(ModuleFeature::PRESTATAIRES);
        $user = $this->lecteur(['contacts.read', 'prestataires.read']);

        $this->assertSame([], $this->idsListe($user, 'contacts.personnes.index'));
        $this->actingAs($user)->get(route('contacts.personnes.show', $p))->assertNotFound();
    }

    public function test_les_donnees_sensibles_de_la_personne_ne_sont_jamais_transmises(): void
    {
        $p = $this->personne(['date_naissance' => '1990-01-01', 'lieu_naissance' => 'Labé', 'nationalite' => 'Guinéenne']);
        $this->client($p);
        $user = $this->lecteur(['contacts.read', 'clients.read']);

        $this->actingAs($user)->get(route('contacts.personnes.show', $p))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('personne.date_naissance')
                ->missing('personne.lieu_naissance')
                ->missing('personne.nationalite')
                ->has('personne.quartier'));
    }

    // ── Parrains et périmètre d'agences ─────────────────────────────────────

    public function test_un_parrain_hors_agence_n_apparait_nulle_part(): void
    {
        $p = $this->personne(['nom_complet' => 'Mamadou Parrain', 'telephone' => '+224622999888']);
        $this->parrain($p, $this->siteB);
        $lecteurA = $this->lecteur(['contacts.read', 'parrains.read', 'clients.read'], $this->siteA);

        $this->actingAs($lecteurA)->get(route('contacts.personnes.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('personnes.total', 0));
        $this->assertSame([], $this->idsListe($lecteurA, 'contacts.personnes.index', ['recherche' => 'Mamadou']));
        $this->actingAs($lecteurA)->get(route('contacts.personnes.show', $p))->assertNotFound();

        $contacts = $this->rechercher($lecteurA, 'Mamadou')['contacts'];
        $this->assertSame(0, $contacts['total']);
        $this->assertStringNotContainsString('622999888', json_encode($this->rechercher($lecteurA, '622999888')));
    }

    public function test_un_parrain_de_son_agence_est_visible(): void
    {
        $p = $this->personne(['nom_complet' => 'Mamadou Parrain']);
        $parrain = $this->parrain($p, $this->siteA);
        $lecteurA = $this->lecteur(['contacts.read', 'parrains.read'], $this->siteA);

        $this->assertSame([$p->id], $this->idsListe($lecteurA, 'contacts.personnes.index'));
        $this->actingAs($lecteurA)->get(route('contacts.personnes.show', $p))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fonctions.0.fonction', 'parrain')
                ->where('fonctions.0.lien', "/backoffice/parrains/{$parrain->id}"));
        $this->assertSame('personnes/'.$p->id, $this->rechercher($lecteurA, 'Mamadou')['contacts']['items'][0]['id']);
    }

    public function test_une_personne_client_et_parrain_hors_agence_ne_montre_que_le_client(): void
    {
        $p = $this->personne();
        $this->client($p);
        $parrain = $this->parrain($p, $this->siteB);
        $lecteurA = $this->lecteur(['contacts.read', 'parrains.read', 'clients.read'], $this->siteA);

        $this->actingAs($lecteurA)->get(route('contacts.personnes.show', $p))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('fonctions', 1)->where('fonctions.0.fonction', 'client'));
        $this->assertStringNotContainsString($parrain->id, $this->actingAs($lecteurA)->get(route('contacts.personnes.index'))->getContent());
    }

    // ── Organisation ────────────────────────────────────────────────────────

    public function test_une_fiche_d_une_autre_organisation_est_introuvable_meme_pour_un_super_admin(): void
    {
        $autre = Organization::factory()->create();
        Feature::for($autre)->activate(ModuleFeature::ACHATS);
        $p = Personne::factory()->create(['organization_id' => $autre->id, 'nom_complet' => 'Ailleurs Diallo']);
        Client::create(['organization_id' => $autre->id, 'personne_id' => $p->id, 'nom_complet' => 'Ailleurs Diallo', 'telephone' => $p->telephone, 'type' => 'externe']);
        $entreprise = EntrepriseTierce::create(['organization_id' => $autre->id, 'raison_sociale' => 'Ailleurs SA']);
        Fournisseur::create(['organization_id' => $autre->id, 'entreprise_tierce_id' => $entreprise->id]);

        foreach ([$this->lecteur(['contacts.read', 'clients.read', 'fournisseurs.read']), $this->lecteur([], null, 'super_admin')] as $user) {
            $this->actingAs($user)->get(route('contacts.personnes.show', $p))->assertNotFound();
            $this->actingAs($user)->get(route('contacts.entreprises.show', $entreprise))->assertNotFound();
            $this->assertSame([], $this->idsListe($user, 'contacts.personnes.index', ['recherche' => 'Ailleurs']));
            $this->assertSame([], $this->idsListe($user, 'contacts.entreprises.index', ['recherche' => 'Ailleurs'], 'entreprises'));
            $this->assertSame(0, $this->rechercher($user, 'Ailleurs')['contacts']['total']);
        }
    }

    public function test_sans_contacts_read_tout_est_refuse(): void
    {
        $p = $this->personne();
        $this->client($p);
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'ABC']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id]);
        $user = $this->lecteur(['clients.read', 'fournisseurs.read']);

        $this->actingAs($user)->get(route('contacts.personnes.index'))->assertForbidden();
        $this->actingAs($user)->get(route('contacts.personnes.show', $p))->assertForbidden();
        $this->actingAs($user)->get(route('contacts.entreprises.index'))->assertForbidden();
        $this->actingAs($user)->get(route('contacts.entreprises.show', $entreprise))->assertForbidden();
        $this->assertArrayNotHasKey('contacts', $this->rechercher($user, 'ABC'));
    }

    // ── Entreprises ─────────────────────────────────────────────────────────

    public function test_liste_et_fiche_entreprise_avec_interlocuteurs(): void
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Société de maintenance', 'nom_commercial' => 'SOMA', 'rccm' => 'GN.TCC.2024', 'is_active' => true]);
        $prestataire = Prestataire::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'type' => PrestataireType::MECANICIEN->value]);
        $mamadou = $this->personne(['nom_complet' => 'Mamadou Diallo']);
        $alpha = $this->personne(['nom_complet' => 'Alpha Camara']);
        $principal = EntrepriseInterlocuteur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'personne_id' => $mamadou->id, 'fonction' => 'Responsable technique']);
        $principal->forceFill(['est_principal' => true])->save();
        EntrepriseInterlocuteur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'personne_id' => $alpha->id, 'fonction' => 'Directeur commercial']);
        $user = $this->lecteur(['contacts.read', 'prestataires.read']);

        $this->actingAs($user)->get(route('contacts.entreprises.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Contacts/Entreprises/Index')
                ->where('entreprises.total', 1)
                ->where('entreprises.data.0.interlocuteur_principal', 'Mamadou Diallo')
                ->where('entreprises.data.0.fonctions.0.id', $prestataire->id)
                ->where('entreprises.data.0.fonctions.0.sous_categorie_libelle', 'Mécanicien'));

        $this->actingAs($user)->get(route('contacts.entreprises.show', $entreprise))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Contacts/Entreprises/Show')
                ->where('entreprise.rccm', 'GN.TCC.2024')
                ->has('interlocuteurs', 2)
                ->where('interlocuteurs.0.nom', 'Mamadou Diallo')
                ->where('interlocuteurs.0.est_principal', true));

        // L'interlocuteur est visible comme personne, avec l'entreprise qu'il représente.
        $this->actingAs($user)->get(route('contacts.personnes.show', $alpha))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('entreprises.0.entreprise_id', $entreprise->id)
                ->where('entreprises.0.fonction', 'Directeur commercial'));
    }

    public function test_une_entreprise_sans_fonction_visible_et_ses_interlocuteurs_sont_masques(): void
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Emballages SA']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id]);
        $interlocuteur = $this->personne(['nom_complet' => 'Ibrahima Sow']);
        EntrepriseInterlocuteur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'personne_id' => $interlocuteur->id]);
        $user = $this->lecteur(['contacts.read', 'clients.read', 'prestataires.read']);

        $this->assertSame([], $this->idsListe($user, 'contacts.entreprises.index', [], 'entreprises'));
        $this->actingAs($user)->get(route('contacts.entreprises.show', $entreprise))->assertNotFound();
        $this->assertSame([], $this->idsListe($user, 'contacts.personnes.index'));
        $this->actingAs($user)->get(route('contacts.personnes.show', $interlocuteur))->assertNotFound();
        $this->assertSame(0, $this->rechercher($user, 'Emballages')['contacts']['total']);
    }

    public function test_filtres_des_entreprises(): void
    {
        $active = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Active SA', 'is_active' => true]);
        $inactive = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Dormante SA', 'is_active' => false]);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $active->id]);
        Prestataire::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $inactive->id, 'type' => PrestataireType::CONSULTANT->value]);
        $user = $this->lecteur(['contacts.read', 'fournisseurs.read', 'prestataires.read']);

        $this->assertSame([$active->id], $this->idsListe($user, 'contacts.entreprises.index', ['statut' => 'actif'], 'entreprises'));
        $this->assertSame([$inactive->id], $this->idsListe($user, 'contacts.entreprises.index', ['fonction' => 'prestataire'], 'entreprises'));
        $this->assertSame([$inactive->id], $this->idsListe($user, 'contacts.entreprises.index', ['sous_categorie' => 'prestataire:consultant'], 'entreprises'));
        $this->assertSame([$active->id], $this->idsListe($user, 'contacts.entreprises.index', ['recherche' => 'Active'], 'entreprises'));
    }

    // ── Recherche globale ───────────────────────────────────────────────────

    public function test_la_recherche_renvoie_personnes_et_entreprises_avec_leur_type(): void
    {
        $p = $this->personne(['nom_complet' => 'Kadiatou Barry']);
        $this->client($p);
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Barry Transport']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id]);
        $user = $this->lecteur(['contacts.read', 'clients.read', 'fournisseurs.read']);

        $items = collect($this->rechercher($user, 'Barry')['contacts']['items']);

        $this->assertEqualsCanonicalizing(['personnes/'.$p->id, 'entreprises/'.$entreprise->id], $items->pluck('id')->all());
        $this->assertStringContainsString('Client', $items->firstWhere('id', 'personnes/'.$p->id)['subtitle']);
    }

    // ── Lecture seule ───────────────────────────────────────────────────────

    /**
     * Les quatre routes de consultation du lot 2 restent en lecture. Les seules écritures du module
     * sont les créations du lot 3, vérifiées par CreationContactsTest.
     */
    public function test_les_routes_de_consultation_restent_en_lecture(): void
    {
        $noms = ['contacts.personnes.index', 'contacts.personnes.show', 'contacts.entreprises.index', 'contacts.entreprises.show'];
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array($r->getName(), $noms, true));

        $this->assertCount(4, $routes);
        $this->assertTrue($routes->every(fn ($r) => $r->methods() === ['GET', 'HEAD']));
    }
}
