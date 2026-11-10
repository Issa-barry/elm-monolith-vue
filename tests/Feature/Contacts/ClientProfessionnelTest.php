<?php

namespace Tests\Feature\Contacts;

use App\Features\ModuleFeature;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Services\Contacts\ProprietaireEntreprise;
use App\Support\Contacts\MessagesContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lot 7b du référentiel Contacts (ADR 0060, CONTACT-047 à CONTACT-049) : client professionnel relié
 * à une fiche Entreprise avec les règles du lot 5 (L7-3) — choix explicite, entreprise non visible
 * jamais révélée (D6), droits D7, lien retiré sans rien supprimer (D8), contact interlocuteur (D9) —
 * et raison sociale comme nom principal du client (L7-2).
 */
class ClientProfessionnelTest extends TestCase
{
    use RefreshDatabase;

    private const DROITS = ['clients.read', 'clients.create', 'clients.update', 'contacts.read'];

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::VEHICULES);
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
    }

    private function utilisateur(array $permissions = self::DROITS): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence '.$user->id, 'type' => 'depot', 'localisation' => 'Conakry']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    /** Sans e-mail : la validation du formulaire Client vérifie le DNS du domaine. */
    private function donnees(array $surcharges = []): array
    {
        return [
            'nom_complet' => 'Awa Camara',
            'telephone' => '622000001',
            'code_pays' => 'GN',
            'ville' => 'Conakry',
            'type' => 'externe',
            'is_active' => true,
            'client_professionnel' => true,
            'raison_sociale' => 'Boulangerie Kaloum',
            ...$surcharges,
        ];
    }

    private function entrepriseFournisseur(string $raisonSociale, array $attributs = []): EntrepriseTierce
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => $raisonSociale, ...$attributs]);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id]);

        return $entreprise;
    }

    private function clientProfessionnel(User $user, array $surcharges = []): Client
    {
        $this->actingAs($user)->post(route('clients.store'), $this->donnees($surcharges))->assertSessionHasNoErrors();

        return Client::where('organization_id', $this->org->id)->latest('id')->firstOrFail();
    }

    // ── Création (L7-2, D3, D9) ─────────────────────────────────────────────

    public function test_creation_cree_la_fiche_entreprise_et_la_raison_sociale_devient_le_nom_du_client(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)
            ->post(route('clients.store'), $this->donnees(['telephone_entreprise' => '624111111', 'identifiant_fiscal' => 'NIF-9']))
            ->assertSessionHasNoErrors();

        $client = Client::where('organization_id', $this->org->id)->firstOrFail();
        $entreprise = $client->entrepriseTierce;
        $this->assertNotNull($entreprise);
        $this->assertSame('Boulangerie Kaloum', $entreprise->raison_sociale);
        $this->assertSame('+224624111111', $entreprise->telephone);
        $this->assertSame('NIF-9', $entreprise->identifiant_fiscal);
        // L7-2 : nom principal = raison sociale ; la Personne garde le nom du contact et son numéro (D2).
        $this->assertSame('Boulangerie Kaloum', $client->nom_complet);
        $this->assertSame('Awa Camara', $client->personne->nom_complet);
        $this->assertSame('+224622000001', $client->personne->telephone);
        $this->assertNull($entreprise->ville);
        $this->assertDatabaseHas('entreprise_interlocuteurs', [
            'entreprise_tierce_id' => $entreprise->id,
            'personne_id' => $client->personne_id,
            'est_principal' => true,
        ]);
    }

    public function test_un_client_particulier_ne_cree_aucune_entreprise(): void
    {
        $this->actingAs($this->utilisateur())
            ->post(route('clients.store'), $this->donnees(['client_professionnel' => false]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, EntrepriseTierce::count());
        $this->assertNull(Client::firstOrFail()->entreprise_tierce_id);
    }

    public function test_la_raison_sociale_est_obligatoire_pour_un_client_professionnel(): void
    {
        $this->actingAs($this->utilisateur())
            ->post(route('clients.store'), $this->donnees(['raison_sociale' => '']))
            ->assertSessionHasErrors('raison_sociale');

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_une_correspondance_exige_un_choix_explicite_puis_relie_l_entreprise_choisie(): void
    {
        $entreprise = $this->entrepriseFournisseur('BOULANGERIE KALOUM');
        $user = $this->utilisateur([...self::DROITS, 'fournisseurs.read']);

        $this->actingAs($user)
            ->post(route('clients.store'), $this->donnees())
            ->assertSessionHasErrors(['entreprise_choix' => ProprietaireEntreprise::MESSAGE_CHOIX]);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseMissing('personnes', ['telephone_normalise' => '224622000001']);

        $this->actingAs($user)
            ->post(route('clients.store'), $this->donnees(['raison_sociale' => 'Autre nom', 'entreprise_choix' => $entreprise->id]))
            ->assertSessionHasNoErrors();

        $client = Client::firstOrFail();
        $this->assertSame($entreprise->id, $client->entreprise_tierce_id);
        // L'entreprise choisie fait foi : la saisie ne la renomme pas.
        $this->assertSame('BOULANGERIE KALOUM', $client->nom_complet);
        $this->assertSame('BOULANGERIE KALOUM', $entreprise->fresh()->raison_sociale);
    }

    public function test_une_entreprise_ne_porte_qu_un_client(): void
    {
        $user = $this->utilisateur();
        $premier = $this->clientProfessionnel($user);

        $this->actingAs($user)
            ->post(route('clients.store'), $this->donnees(['telephone' => '622000002', 'entreprise_choix' => $premier->entreprise_tierce_id]))
            ->assertSessionHasErrors('entreprise_choix');

        $this->assertSame(1, Client::where('entreprise_tierce_id', $premier->entreprise_tierce_id)->count());
    }

    public function test_une_entreprise_proprietaire_peut_aussi_etre_cliente(): void
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Transports Boké']);
        Proprietaire::factory()->entreprise('Transports Boké')->create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id]);
        $user = $this->utilisateur([...self::DROITS, 'proprietaires.read']);

        $this->actingAs($user)
            ->post(route('clients.store'), $this->donnees(['raison_sociale' => 'Transports Boké', 'entreprise_choix' => $entreprise->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($entreprise->id, Client::firstOrFail()->entreprise_tierce_id);
    }

    // ── Visibilité (D6) ─────────────────────────────────────────────────────

    public function test_telephone_d_une_entreprise_non_visible_message_neutre_et_arret(): void
    {
        $this->entrepriseFournisseur('Secret SARL', ['telephone' => '+224624111111', 'telephone_normalise' => '224624111111']);

        $this->actingAs($this->utilisateur())
            ->post(route('clients.store'), $this->donnees(['telephone_entreprise' => '624111111']))
            ->assertSessionHasErrors(['telephone_entreprise' => MessagesContacts::NUMERO_NON_VISIBLE]);

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_la_recherche_signale_une_entreprise_deja_cliente_et_exclut_le_client_courant(): void
    {
        $user = $this->utilisateur();
        $client = $this->clientProfessionnel($user);

        $this->actingAs($user)
            ->getJson(route('clients.entreprises-correspondantes', ['raison_sociale' => 'boulangerie  KALOUM']))
            ->assertOk()
            ->assertJsonPath('correspondances.0.id', $client->entreprise_tierce_id)
            ->assertJsonPath('correspondances.0.deja_pris', true)
            ->assertJsonPath('correspondances.0.selectionnable', false);

        $this->actingAs($user)
            ->getJson(route('clients.entreprises-correspondantes', ['raison_sociale' => 'Boulangerie Kaloum', 'client' => $client->id]))
            ->assertOk()
            ->assertJsonPath('correspondances.0.deja_pris', false);
    }

    public function test_la_recherche_exige_le_droit_de_creer_ou_de_modifier_un_client(): void
    {
        $this->actingAs($this->utilisateur(['clients.read']))
            ->getJson(route('clients.entreprises-correspondantes', ['raison_sociale' => 'X']))
            ->assertForbidden();
    }

    // ── Modification (D5, D7) ───────────────────────────────────────────────

    public function test_l_edition_presente_le_contact_et_l_entreprise_reliee(): void
    {
        $user = $this->utilisateur();
        $client = $this->clientProfessionnel($user);

        $this->actingAs($user)
            ->get(route('clients.edit', $client))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('client.nom_complet', 'Awa Camara')
                ->where('client.client_professionnel', true)
                ->where('client.raison_sociale', 'Boulangerie Kaloum')
                ->where('entreprise.id', $client->entreprise_tierce_id)
                ->where('entreprise.partagee', false)
                ->where('entreprise.peut_modifier_identite', true));
    }

    public function test_renommer_l_entreprise_depuis_le_client_renomme_le_client_et_garde_le_contact(): void
    {
        $user = $this->utilisateur();
        $client = $this->clientProfessionnel($user);

        $this->actingAs($user)
            ->put(route('clients.update', $client), $this->donnees(['raison_sociale' => 'Boulangerie du Port', 'nom_complet' => 'Awa Camara Bah']))
            ->assertSessionHasNoErrors();

        $client->refresh();
        // Saisie mise en forme comme pour un propriétaire (PhoneHandlerTrait::normalizeData).
        $this->assertSame('Boulangerie Du Port', $client->entrepriseTierce->raison_sociale);
        $this->assertSame('Boulangerie Du Port', $client->nom_complet);
        $this->assertSame('Awa Camara Bah', $client->personne->fresh()->nom_complet);
    }

    public function test_renommer_l_entreprise_depuis_les_contacts_se_repercute_sur_le_client(): void
    {
        $client = $this->clientProfessionnel($this->utilisateur());

        $client->entrepriseTierce->update(['raison_sociale' => 'Kaloum Pains']);

        $this->assertSame('Kaloum Pains', $client->fresh()->nom_complet);
    }

    public function test_entreprise_partagee_sans_contacts_update_refusee_et_inchangee(): void
    {
        $user = $this->utilisateur();
        $client = $this->clientProfessionnel($user);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $client->entreprise_tierce_id]);

        $this->actingAs($user)
            ->put(route('clients.update', $client), $this->donnees(['raison_sociale' => 'Renommée', 'confirmer_modification_entreprise' => true]))
            ->assertSessionHasErrors(['raison_sociale' => ProprietaireEntreprise::MESSAGE_DROIT_PARTAGEE]);

        $this->assertSame('Boulangerie Kaloum', $client->entrepriseTierce->fresh()->raison_sociale);
        $this->assertSame('Boulangerie Kaloum', $client->fresh()->nom_complet);
    }

    public function test_entreprise_partagee_avec_contacts_update_exige_la_confirmation(): void
    {
        $user = $this->utilisateur([...self::DROITS, 'contacts.update']);
        $client = $this->clientProfessionnel($user);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $client->entreprise_tierce_id]);

        $this->actingAs($user)
            ->put(route('clients.update', $client), $this->donnees(['raison_sociale' => 'Renommée']))
            ->assertSessionHasErrors(['confirmer_modification_entreprise' => ProprietaireEntreprise::MESSAGE_CONFIRMATION]);

        $this->actingAs($user)
            ->put(route('clients.update', $client), $this->donnees(['raison_sociale' => 'Renommée', 'confirmer_modification_entreprise' => true]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Renommée', $client->fresh()->nom_complet);
    }

    public function test_un_client_particulier_devenu_professionnel_est_relie(): void
    {
        $user = $this->utilisateur();
        $this->actingAs($user)->post(route('clients.store'), $this->donnees(['client_professionnel' => false]))->assertSessionHasNoErrors();
        $client = Client::firstOrFail();

        $this->actingAs($user)
            ->put(route('clients.update', $client), $this->donnees())
            ->assertSessionHasNoErrors();

        $client->refresh();
        $this->assertNotNull($client->entreprise_tierce_id);
        $this->assertSame('Boulangerie Kaloum', $client->nom_complet);
    }

    // ── D8 : redevenir particulier ──────────────────────────────────────────

    public function test_redevenir_particulier_retire_le_lien_et_conserve_l_entreprise(): void
    {
        $user = $this->utilisateur();
        $client = $this->clientProfessionnel($user);
        $entrepriseId = $client->entreprise_tierce_id;

        $this->actingAs($user)
            ->put(route('clients.update', $client), $this->donnees(['client_professionnel' => false]))
            ->assertSessionHasNoErrors();

        $client->refresh();
        $this->assertNull($client->entreprise_tierce_id);
        $this->assertSame('Awa Camara', $client->nom_complet);
        $this->assertNotNull(EntrepriseTierce::find($entrepriseId));
        $this->assertDatabaseHas('entreprise_interlocuteurs', ['entreprise_tierce_id' => $entrepriseId, 'personne_id' => $client->personne_id]);
        $this->assertTrue(AuditLog::where('auditable_id', $client->id)->where('meta->origine', 'client_particulier')->exists());
    }

    // ── Contacts et affichage (L7-2, D10) ───────────────────────────────────

    public function test_la_fiche_entreprise_porte_la_fonction_client_et_la_fiche_personne_la_mention_au_nom_de(): void
    {
        $user = $this->utilisateur();
        $client = $this->clientProfessionnel($user);

        $this->actingAs($user)
            ->get(route('contacts.entreprises.show', $client->entreprise_tierce_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fonctions.0.fonction', 'client')
                ->where('fonctions.0.id', $client->id)
                ->where('interlocuteurs.0.personne_id', $client->personne_id));

        $this->actingAs($user)
            ->get(route('contacts.personnes.show', $client->personne_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fonctions.0.fonction', 'client')
                ->where('fonctions.0.au_nom_de', 'Boulangerie Kaloum')
                ->where('fonctions.0.entreprise_lien', "/backoffice/contacts/entreprises/{$client->entreprise_tierce_id}"));
    }

    public function test_sans_clients_read_l_entreprise_seulement_cliente_reste_invisible(): void
    {
        $client = $this->clientProfessionnel($this->utilisateur());

        $this->actingAs($this->utilisateur(['contacts.read', 'fournisseurs.read']))
            ->get(route('contacts.entreprises.show', $client->entreprise_tierce_id))
            ->assertNotFound();
    }

    public function test_la_fiche_client_affiche_la_raison_sociale_et_le_contact_en_mention(): void
    {
        $user = $this->utilisateur();
        $client = $this->clientProfessionnel($user);

        $this->actingAs($user)
            ->get(route('clients.show', $client))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('client.nom_complet', 'Boulangerie Kaloum')
                ->where('client.contact_nom', 'Awa Camara'));
    }

    public function test_le_journal_ne_contient_ni_nom_ni_telephone(): void
    {
        $client = $this->clientProfessionnel($this->utilisateur(), ['telephone_entreprise' => '624111111']);

        $journal = AuditLog::where('auditable_id', $client->id)->get();
        $this->assertNotEmpty($journal);
        foreach ($journal as $entree) {
            $contenu = json_encode([$entree->old_values, $entree->new_values, $entree->meta]);
            $this->assertStringNotContainsString('Boulangerie', $contenu);
            $this->assertStringNotContainsString('Camara', $contenu);
            $this->assertStringNotContainsString('624111111', $contenu);
        }
        $this->assertSame(1, EntrepriseInterlocuteur::where('personne_id', $client->personne_id)->count());
        $this->assertSame(1, Personne::where('telephone_normalise', '224622000001')->count());
    }
}
