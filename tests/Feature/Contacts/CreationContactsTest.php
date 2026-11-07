<?php

namespace Tests\Feature\Contacts;

use App\Enums\PrestataireType;
use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\CommandeAchat;
use App\Models\CommandeVente;
use App\Models\Depense;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Support\Contacts\MessagesContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lot 3 du référentiel Contacts (ADR 0060, CONTACT-017 à CONTACT-020) : création « téléphone
 * d'abord », ajout explicite d'une fonction, une fonction par catégorie, fin de la reprise
 * silencieuse dans les formulaires Fournisseur et Prestataire, et règle D17.
 */
class CreationContactsTest extends TestCase
{
    use RefreshDatabase;

    private const TOUS_LES_DROITS = [
        'contacts.read', 'contacts.create',
        'fournisseurs.read', 'fournisseurs.create',
        'prestataires.read', 'prestataires.create',
        'clients.read', 'parrains.read',
    ];

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

    private function utilisateur(array $permissions = self::TOUS_LES_DROITS, string $role = 'manager', ?Organization $org = null, ?Site $site = null): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $org ??= $this->org;
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);
        $user->sites()->attach($site?->id ?? ($org->is($this->org) ? $this->siteA->id : Site::create(['organization_id' => $org->id, 'nom' => 'Siège', 'type' => 'depot', 'localisation' => 'Conakry'])->id), ['role' => 'employe', 'is_default' => true]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function personne(string $telephone, array $attributs = []): Personne
    {
        return Personne::factory()->create(['organization_id' => $this->org->id, 'telephone' => $telephone, ...$attributs]);
    }

    private function nouvellePersonne(array $donnees = []): array
    {
        return [
            'prenom' => 'mamadou', 'nom' => 'diallo', 'telephone' => '+224622100001', 'ville' => 'conakry', 'quartier' => 'kaloum',
            'fonction' => 'prestataire', 'type' => PrestataireType::MECANICIEN->value, ...$donnees,
        ];
    }

    private function rechercher(User $user, string $telephone, string $type = 'personne'): array
    {
        return $this->actingAs($user)
            ->getJson(route('contacts.recherche-telephone', ['type' => $type, 'telephone' => $telephone]))
            ->assertOk()
            ->json();
    }

    // ── Recherche par téléphone ─────────────────────────────────────────────

    public function test_un_numero_libre(): void
    {
        $r = $this->rechercher($this->utilisateur(), '+224 622 100 001');

        $this->assertSame('libre', $r['statut']);
        $this->assertSame('+224622100001', $r['telephone']);
    }

    public function test_un_numero_connu_et_visible_presente_la_fiche_sans_rien_rattacher(): void
    {
        $p = $this->personne('+224622100002', ['nom_complet' => 'Fatou Bah', 'ville' => 'Kindia']);
        $fournisseur = Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $p->id]);
        $user = $this->utilisateur();
        $avant = [Personne::count(), Prestataire::count(), Fournisseur::count()];

        $r = $this->rechercher($user, '+224622100002');

        $this->assertSame('connu', $r['statut']);
        $this->assertSame($p->id, $r['fiche']['id']);
        $this->assertSame('Fatou Bah', $r['fiche']['nom']);
        $this->assertSame($fournisseur->id, $r['fiche']['fonctions'][0]['id']);
        $this->assertSame([['value' => 'prestataire', 'label' => 'Prestataire']], $r['fiche']['fonctions_ajoutables']);
        $this->assertSame([Personne::count(), Prestataire::count(), Fournisseur::count()], $avant);
    }

    public function test_un_numero_d_une_fiche_non_visible_ne_revele_rien(): void
    {
        $parrain = $this->personne('+224622100003', ['nom_complet' => 'Secret Parrain']);
        $p = Parrain::create(['organization_id' => $this->org->id, 'personne_id' => $parrain->id, 'is_active' => true]);
        Vehicule::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->siteB->id, 'parrain_id' => $p->id]);
        $compteSeul = $this->personne('+224622100004', ['nom_complet' => 'Compte Seul']);

        foreach (['+224622100003' => $parrain, '+224622100004' => $compteSeul] as $telephone => $fiche) {
            $reponse = $this->actingAs($this->utilisateur(site: $this->siteA))
                ->getJson(route('contacts.recherche-telephone', ['type' => 'personne', 'telephone' => $telephone]))
                ->assertOk();

            $this->assertSame('indisponible', $reponse->json('statut'));
            $this->assertSame(MessagesContacts::NUMERO_NON_VISIBLE, $reponse->json('message'));
            $this->assertArrayNotHasKey('fiche', $reponse->json());
            $this->assertStringNotContainsString($fiche->id, $reponse->getContent());
            $this->assertStringNotContainsString($fiche->nom_complet, $reponse->getContent());
        }
    }

    public function test_un_numero_invalide_et_l_acces_sans_droit(): void
    {
        $this->assertSame('invalide', $this->rechercher($this->utilisateur(), '123')['statut']);

        $sansDroit = $this->utilisateur(['contacts.read', 'prestataires.read']);
        $this->actingAs($sansDroit)->getJson(route('contacts.recherche-telephone', ['type' => 'personne', 'telephone' => '+224622100001']))->assertForbidden();
        $this->actingAs($sansDroit)->get(route('contacts.create'))->assertForbidden();
        $this->actingAs($sansDroit)->post(route('contacts.personnes.store'), $this->nouvellePersonne())->assertForbidden();
        $this->assertSame(0, Prestataire::count());
    }

    // ── Création ────────────────────────────────────────────────────────────

    public function test_creation_d_une_personne_et_de_sa_premiere_fonction(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)->get(route('contacts.create', ['type' => 'personne']))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Contacts/Creer')->where('type', 'personne')->has('fonctions_creables', 2));

        $reponse = $this->actingAs($user)->post(route('contacts.personnes.store'), $this->nouvellePersonne(['type' => PrestataireType::CONSULTANT->value]));

        $personne = Personne::where('telephone_normalise', '224622100001')->sole();
        $reponse->assertRedirect(route('contacts.personnes.show', $personne));
        $this->assertSame('DIALLO', $personne->nom);
        $this->assertSame('Mamadou', $personne->prenom);
        $this->assertSame('Kaloum', $personne->quartier);
        $this->assertSame('GN', $personne->code_pays);
        $prestataire = Prestataire::where('personne_id', $personne->id)->sole();
        $this->assertSame(PrestataireType::CONSULTANT, $prestataire->type);
        $this->assertStringStartsWith('P', $prestataire->reference);
        $this->assertTrue($prestataire->is_active);
    }

    public function test_creation_d_une_entreprise_fournisseur(): void
    {
        $this->actingAs($this->utilisateur())->post(route('contacts.entreprises.store'), [
            'raison_sociale' => 'Emballages SA', 'nom_commercial' => 'EMBA', 'rccm' => 'GN.TCC.2025.B.1', 'telephone' => '+224622100005',
            'ville' => 'Conakry', 'fonction' => 'fournisseur',
        ])->assertRedirect();

        $entreprise = EntrepriseTierce::where('telephone_normalise', '224622100005')->sole();
        $this->assertSame('EMBA', $entreprise->nom_commercial);
        $this->assertTrue(Fournisseur::where('entreprise_tierce_id', $entreprise->id)->exists());
    }

    public function test_un_numero_connu_n_est_jamais_repris_par_la_creation(): void
    {
        $this->personne('+224622100006', ['nom_complet' => 'Déjà Là']);
        $user = $this->utilisateur();
        $avant = Personne::count();

        $this->actingAs($user)->post(route('contacts.personnes.store'), $this->nouvellePersonne(['telephone' => '+224622100006']))
            ->assertSessionHasErrors(['telephone' => MessagesContacts::NUMERO_CONNU]);

        $this->assertSame($avant, Personne::count());
        $this->assertSame(0, Prestataire::count());
    }

    public function test_deux_creations_simultanees_du_meme_numero_ne_creent_qu_une_fiche(): void
    {
        // Simule l'autre requête : elle enregistre le même numéro entre la vérification et l'insertion.
        $concurrente = false;
        Personne::creating(function (Personne $p) use (&$concurrente) {
            if (! $concurrente && $p->telephone_normalise === '224622100007') {
                $concurrente = true;
                DB::table('personnes')->insert(['id' => strtolower((string) Str::ulid()), 'organization_id' => $p->organization_id, 'telephone' => '+224622100007', 'telephone_normalise' => '224622100007', 'nom_complet' => 'Autre requête', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->actingAs($this->utilisateur())->post(route('contacts.personnes.store'), $this->nouvellePersonne(['telephone' => '+224622100007']))
            ->assertSessionHasErrors(['telephone' => MessagesContacts::NUMERO_CONNU]);

        $this->assertSame(0, Personne::where('telephone_normalise', '224622100007')->count(), 'Tout est annulé dans la transaction, y compris la ligne simulée.');
        $this->assertSame(0, Prestataire::count(), 'Aucune fonction orpheline.');
    }

    public function test_une_fonction_non_autorisee_ou_dont_le_module_est_inactif_est_refusee(): void
    {
        $user = $this->utilisateur(['contacts.read', 'contacts.create', 'fournisseurs.read', 'fournisseurs.create']);
        $this->actingAs($user)->post(route('contacts.personnes.store'), $this->nouvellePersonne())->assertSessionHasErrors('fonction');

        Feature::for($this->org)->deactivate(ModuleFeature::ACHATS);
        $this->actingAs($user)->get(route('contacts.create'))->assertForbidden();
        $this->actingAs($user)->post(route('contacts.personnes.store'), $this->nouvellePersonne(['fonction' => 'fournisseur']))->assertSessionHasErrors('fonction');

        $this->assertSame(0, Personne::where('telephone_normalise', '224622100001')->count());
    }

    // ── Ajout d'une fonction ────────────────────────────────────────────────

    public function test_ajouter_une_fonction_a_une_fiche_existante(): void
    {
        $p = $this->personne('+224622100008');
        Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $p->id]);
        $user = $this->utilisateur();
        $avant = Personne::count();

        $this->actingAs($user)->post(route('contacts.personnes.fonctions.store', $p), ['fonction' => 'prestataire', 'type' => PrestataireType::MACHINISTE->value])
            ->assertRedirect(route('contacts.personnes.show', ['personne' => $p, 'tab' => 'fonctions']));

        $this->assertSame($avant, Personne::count());
        $this->assertSame(PrestataireType::MACHINISTE, Prestataire::where('personne_id', $p->id)->sole()->type);
    }

    public function test_une_seule_fonction_de_chaque_categorie_par_fiche(): void
    {
        $p = $this->personne('+224622100009');
        Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $p->id, 'type' => PrestataireType::MECANICIEN->value, 'is_active' => false]);
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'ABC', 'telephone' => '+224622100010', 'telephone_normalise' => '224622100010']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id]);
        $user = $this->utilisateur();

        $this->actingAs($user)->post(route('contacts.personnes.fonctions.store', $p), ['fonction' => 'prestataire', 'type' => PrestataireType::CONSULTANT->value])
            ->assertSessionHasErrors(['fonction' => "Cette fiche a déjà une fonction Prestataire, désactivée : réactivez-la depuis l'écran Prestataires au lieu d'en créer une seconde."]);
        $this->actingAs($user)->post(route('contacts.entreprises.fonctions.store', $entreprise), ['fonction' => 'fournisseur'])
            ->assertSessionHasErrors('fonction');

        $this->assertSame(1, Prestataire::where('personne_id', $p->id)->count());
        $this->assertSame(1, Fournisseur::where('entreprise_tierce_id', $entreprise->id)->count());
        $this->actingAs($user)->get(route('contacts.personnes.show', $p))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('fonctions_ajoutables', [['value' => 'fournisseur', 'label' => 'Fournisseur']]));
    }

    public function test_l_ajout_verrouille_la_fiche_avant_d_ecrire(): void
    {
        $p = $this->personne('+224622100011');
        Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $p->id]);
        $user = $this->utilisateur();

        DB::enableQueryLog();
        $this->actingAs($user)->post(route('contacts.personnes.fonctions.store', $p), ['fonction' => 'prestataire', 'type' => PrestataireType::MECANICIEN->value]);
        $requetes = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $insertion = $requetes->search(fn (string $q) => str_starts_with(strtolower($q), 'insert') && str_contains($q, 'prestataires'));
        $this->assertNotFalse($insertion);
        $lectures = $requetes->take($insertion)->filter(fn (string $q) => str_starts_with(strtolower($q), 'select')
            && (str_contains($q, '"personnes"') || str_contains($q, '`personnes`')));
        $this->assertNotEmpty($lectures);
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertTrue($lectures->contains(fn (string $q) => str_contains(strtolower($q), 'for update')), 'La fiche n\'est pas verrouillée avant l\'ajout.');
        }
    }

    public function test_ajouter_une_fonction_a_une_fiche_non_visible_ou_d_une_autre_organisation_renvoie_404(): void
    {
        $parrain = $this->personne('+224622100012');
        $p = Parrain::create(['organization_id' => $this->org->id, 'personne_id' => $parrain->id, 'is_active' => true]);
        Vehicule::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->siteB->id, 'parrain_id' => $p->id]);
        $autreOrg = Organization::factory()->create();
        $etranger = Personne::factory()->create(['organization_id' => $autreOrg->id]);
        Fournisseur::create(['organization_id' => $autreOrg->id, 'personne_id' => $etranger->id]);

        $this->actingAs($this->utilisateur(site: $this->siteA))->post(route('contacts.personnes.fonctions.store', $parrain), ['fonction' => 'fournisseur'])->assertNotFound();
        foreach ([$this->utilisateur(), $this->utilisateur([], 'super_admin')] as $user) {
            $this->actingAs($user)->post(route('contacts.personnes.fonctions.store', $etranger), ['fonction' => 'prestataire', 'type' => 'mecanicien'])->assertNotFound();
        }

        $this->assertSame(0, Fournisseur::where('personne_id', $parrain->id)->count());
        $this->assertSame(0, Prestataire::where('personne_id', $etranger->id)->count());
    }

    public function test_un_consultant_ajoute_est_relie_au_compte_comme_avant(): void
    {
        $p = $this->personne('+224622100013');
        Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $p->id]);
        $compte = User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $p->id]);
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $compte->assignRole('client');

        $this->actingAs($this->utilisateur())->post(route('contacts.personnes.fonctions.store', $p), ['fonction' => 'prestataire', 'type' => PrestataireType::CONSULTANT->value]);

        $this->assertSame($compte->id, Prestataire::where('personne_id', $p->id)->sole()->user_id);
    }

    // ── Fin de la reprise silencieuse (anciens formulaires) ─────────────────

    public function test_les_anciens_formulaires_refusent_un_numero_deja_connu(): void
    {
        $this->personne('+224622100014', ['nom_complet' => 'Fatou Bah']);
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Société Maintenance', 'telephone' => '+224622100015', 'telephone_normalise' => '224622100015']);
        Prestataire::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'type' => PrestataireType::MECANICIEN->value]);
        $user = $this->utilisateur();
        $avant = [Personne::count(), EntrepriseTierce::count(), Prestataire::count(), Fournisseur::count()];

        $prestataire = $this->actingAs($user)->post(route('prestataires.store'), [
            'prenom' => 'Autre', 'nom' => 'Nom', 'phone' => '622100014', 'code_pays' => 'GN', 'ville' => 'Conakry', 'type' => 'machiniste',
        ]);
        $prestataire->assertSessionHasErrors(['phone' => MessagesContacts::NUMERO_CONNU]);
        $this->assertStringNotContainsString('Fatou', session('errors')->first('phone'));

        $this->actingAs($user)->post(route('produits.fournisseurs.store'), [
            'raison_sociale' => 'Société Maintenance', 'phone' => '622100015', 'code_pays' => 'GN',
        ])->assertSessionHasErrors(['phone' => MessagesContacts::NUMERO_CONNU]);

        $this->assertSame($avant, [Personne::count(), EntrepriseTierce::count(), Prestataire::count(), Fournisseur::count()]);
    }

    public function test_les_anciens_formulaires_fonctionnent_toujours_avec_un_numero_libre(): void
    {
        $this->actingAs($this->utilisateur())->post(route('prestataires.store'), [
            'prenom' => 'Alpha', 'nom' => 'Camara', 'phone' => '622100016', 'code_pays' => 'GN', 'ville' => 'Conakry', 'type' => 'machiniste',
        ])->assertSessionHasNoErrors();

        $prestataire = Prestataire::with('personne')->sole();
        $this->assertSame('224622100016', $prestataire->personne->telephone_normalise);
    }

    public function test_modifier_vers_un_numero_deja_pris_donne_un_message_et_non_une_erreur_sql(): void
    {
        $this->personne('+224622100017');
        $p = $this->personne('+224622100018');
        $prestataire = Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $p->id, 'type' => PrestataireType::MECANICIEN->value]);

        $this->actingAs($this->utilisateur(['prestataires.read', 'prestataires.update']))->put(route('prestataires.update', $prestataire), [
            'prenom' => 'Alpha', 'nom' => 'Camara', 'phone' => '622100017', 'code_pays' => 'GN', 'ville' => 'Conakry', 'type' => 'mecanicien',
        ])->assertSessionHasErrors('phone');

        $this->assertSame('224622100018', $p->fresh()->telephone_normalise);
    }

    // ── D17 ─────────────────────────────────────────────────────────────────

    public function test_d17_une_fonction_avec_historique_ne_se_supprime_pas(): void
    {
        $droits = ['clients.read', 'clients.delete', 'fournisseurs.read', 'fournisseurs.delete', 'prestataires.read', 'prestataires.delete', 'proprietaires.read', 'proprietaires.delete'];
        $user = $this->utilisateur($droits);

        $client = Client::create(['organization_id' => $this->org->id, 'nom_complet' => 'Client vendu', 'telephone' => '+224622100020', 'type' => 'externe']);
        CommandeVente::factory()->create(['organization_id' => $this->org->id, 'client_id' => $client->id]);
        $fournisseur = Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $this->personne('+224622100021')->id]);
        CommandeAchat::create(['organization_id' => $this->org->id, 'fournisseur_id' => $fournisseur->id, 'total_commande' => 5000]);
        $prestataire = Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $this->personne('+224622100022')->id, 'type' => 'consultant']);
        Depense::factory()->create(['organization_id' => $this->org->id, 'beneficiaire_type' => 'prestataire', 'beneficiaire_id' => $prestataire->id]);
        $proprietaire = Proprietaire::create(['organization_id' => $this->org->id, 'personne_id' => $this->personne('+224622100023')->id, 'type' => 'personne_physique', 'is_active' => true]);
        Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $proprietaire->id]);

        foreach ([
            ['clients.destroy', $client], ['fournisseurs.destroy', $fournisseur],
            ['prestataires.destroy', $prestataire], ['proprietaires.destroy', $proprietaire],
        ] as [$route, $fonction]) {
            $this->actingAs($user)->delete(route($route, $fonction))->assertSessionHas('error');
            $this->assertNotSoftDeleted($fonction);
        }

        $this->actingAs($user)->get(route('clients.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clients.0.supprimable', false));
        $this->actingAs($user)->get(route('prestataires.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('prestataires.0.supprimable', false));
    }

    public function test_d17_une_fonction_sans_historique_se_supprime_comme_avant(): void
    {
        $user = $this->utilisateur(['prestataires.read', 'prestataires.delete']);
        $prestataire = Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $this->personne('+224622100024')->id, 'type' => 'machiniste']);

        $this->actingAs($user)->get(route('prestataires.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('prestataires.0.supprimable', true));
        $this->actingAs($user)->delete(route('prestataires.destroy', $prestataire))->assertSessionHas('success');

        $this->assertSoftDeleted($prestataire);
    }

    // ── Routes ──────────────────────────────────────────────────────────────

    /** Inventaire exact : les 4 créations du lot 3 et les 5 écritures d'interlocuteurs du lot 4. */
    public function test_les_routes_d_ecriture_du_module_sont_celles_prevues(): void
    {
        $ecritures = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'contacts.') && $r->methods() !== ['GET', 'HEAD'])
            ->map(fn ($r) => $r->getName().':'.implode('|', $r->methods()))
            ->sort()->values()->all();

        $this->assertSame([
            'contacts.entreprises.fonctions.store:POST',
            'contacts.entreprises.interlocuteurs.desactiver:POST',
            'contacts.entreprises.interlocuteurs.principal:POST',
            'contacts.entreprises.interlocuteurs.reactiver:POST',
            'contacts.entreprises.interlocuteurs.store:POST',
            'contacts.entreprises.interlocuteurs.update:PATCH',
            'contacts.entreprises.store:POST',
            'contacts.personnes.fonctions.store:POST',
            'contacts.personnes.store:POST',
        ], $ecritures);
    }
}
