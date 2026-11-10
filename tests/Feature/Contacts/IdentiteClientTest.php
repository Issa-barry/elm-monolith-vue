<?php

namespace Tests\Feature\Contacts;

use App\Enums\ClientType;
use App\Features\ModuleFeature;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ContactsReprise;
use App\Models\ContactsRepriseValeur;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Support\Contacts\MessagesContacts;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lot 7a du référentiel Contacts (ADR 0060, R1, D16, L7-1, CONTACT-043 à CONTACT-046) : rapport et
 * arbitrage des écarts client / personne (complétion des seuls champs vides, conflits arbitrés client
 * par client, exécution annulable), activation de la synchronisation par organisation, création d'un
 * client sur une Personne existante, modification de l'identité depuis les fiches Contacts.
 */
class IdentiteClientTest extends TestCase
{
    use RefreshDatabase;

    private const COMMANDE = 'contacts:ecarts-clients';

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
        Feature::for($this->org)->activate(ModuleFeature::VEHICULES);
    }

    private function utilisateur(array $permissions = ['clients.read', 'clients.create', 'clients.update', 'contacts.read', 'contacts.update']): User
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

    private function personne(array $attributs = []): Personne
    {
        return Personne::factory()->create(['organization_id' => $this->org->id, 'nom' => null, 'prenom' => null, 'email' => null, 'ville' => null, 'adresse' => null, ...$attributs]);
    }

    private function client(Personne $personne, array $attributs = []): Client
    {
        return Client::create([
            'organization_id' => $this->org->id, 'personne_id' => $personne->id, 'type' => ClientType::EXTERNE->value,
            'nom_complet' => $personne->getAttributes()['nom_complet'] ?? null, 'telephone' => $personne->telephone, ...$attributs,
        ]);
    }

    private function lancer(array $options = []): int
    {
        return $this->artisan(self::COMMANDE, ['--acteur' => $this->utilisateur()->id, ...$options])->run();
    }

    // ── Rapport et arbitrage (D16, L7-1) ────────────────────────────────────

    public function test_la_simulation_liste_completions_et_conflits_sans_rien_ecrire(): void
    {
        $p = $this->personne(['nom_complet' => 'Fatou Bah', 'telephone' => '+224622000001', 'ville' => 'Conakry']);
        $c = $this->client($p, ['nom_complet' => 'Fatou Diallo', 'email' => 'fatou@exemple.gn', 'ville' => null]);

        $this->artisan(self::COMMANDE, ['--acteur' => $this->utilisateur()->id])
            ->expectsOutputToContain('Conflits à arbitrer : 1')
            ->expectsOutputToContain('Simulation')
            ->assertSuccessful();

        $this->assertNull($p->fresh()->email);
        $this->assertNull($c->fresh()->ville);
        $this->assertSame(0, ContactsReprise::count());
    }

    public function test_l_application_complete_les_champs_vides_et_laisse_les_conflits(): void
    {
        $p = $this->personne(['nom_complet' => 'Fatou Bah', 'telephone' => '+224622000001', 'ville' => 'Conakry']);
        $c = $this->client($p, ['nom_complet' => 'Fatou Diallo', 'email' => 'fatou@exemple.gn', 'ville' => null]);

        $this->assertSame(Command::SUCCESS, $this->lancer(['--apply' => true]));

        $this->assertSame('fatou@exemple.gn', $p->fresh()->email);
        $this->assertSame('Conakry', $c->fresh()->ville);
        // Conflit : rien n'est tranché automatiquement.
        $this->assertSame('Fatou Bah', $p->fresh()->getAttributes()['nom_complet']);
        $this->assertSame('Fatou Diallo', $c->fresh()->nom_complet);
        $this->assertSame(2, ContactsRepriseValeur::count());
    }

    public function test_l_arbitrage_se_fait_client_par_client(): void
    {
        $p1 = $this->personne(['nom_complet' => 'Fatou Bah', 'telephone' => '+224622000001']);
        $c1 = $this->client($p1, ['nom_complet' => 'Fatou Diallo']);
        $p2 = $this->personne(['nom_complet' => 'Awa Camara', 'telephone' => '+224622000002']);
        $c2 = $this->client($p2, ['nom_complet' => 'Awa Sylla']);

        $this->lancer(['--apply' => true, '--personne-fait-foi' => [$c1->id], '--client-fait-foi' => [$c2->id]]);

        $this->assertSame('Fatou Bah', $c1->fresh()->nom_complet);
        $this->assertSame('Awa Sylla', $p2->fresh()->getAttributes()['nom_complet']);
    }

    public function test_deux_clients_qui_proposent_deux_valeurs_pour_un_champ_vide_sont_en_conflit(): void
    {
        $p = $this->personne(['nom_complet' => 'Fatou Bah', 'telephone' => '+224622000001']);
        $this->client($p, ['email' => 'un@exemple.gn']);
        $this->client($p, ['email' => 'deux@exemple.gn', 'telephone' => '+224622000099']);

        $this->lancer(['--apply' => true]);

        $this->assertNull($p->fresh()->email);
    }

    public function test_l_annulation_restaure_et_signale_ce_qui_a_change_depuis(): void
    {
        $p = $this->personne(['nom_complet' => 'Fatou Bah', 'telephone' => '+224622000001', 'ville' => 'Conakry']);
        $c = $this->client($p, ['email' => 'fatou@exemple.gn', 'ville' => null]);
        $this->lancer(['--apply' => true]);
        $reprise = ContactsReprise::where('mode', ContactsReprise::MODE_APPLICATION)->firstOrFail();
        // Modifié depuis l'exécution : l'annulation n'y touche pas.
        DB::table('clients')->where('id', $c->id)->update(['ville' => 'Kindia']);

        $this->lancer(['--annuler' => $reprise->id, '--apply' => true]);

        $this->assertNull($p->fresh()->email);
        $this->assertSame('Kindia', $c->fresh()->ville);
        $this->assertSame(1, ContactsRepriseValeur::where('annulation_statut', ContactsRepriseValeur::CONFLIT)->count());
        $this->assertSame(1, ContactsRepriseValeur::where('annulation_statut', ContactsRepriseValeur::ANNULEE)->count());
    }

    // ── Activation (D16) ────────────────────────────────────────────────────

    public function test_l_activation_est_refusee_tant_qu_un_conflit_reste_puis_aligne_l_ecriture(): void
    {
        $p = $this->personne(['nom_complet' => 'Fatou Bah', 'telephone' => '+224622000001']);
        $c = $this->client($p, ['nom_complet' => 'Fatou Diallo']);

        $this->assertSame(Command::INVALID, $this->lancer(['--activer' => true]));
        $this->assertNull($this->org->fresh()->synchronisation_identite_client_le);

        // Une simple différence de casse n'est pas un conflit : l'activation aligne sur la Personne.
        DB::table('clients')->where('id', $c->id)->update(['nom_complet' => 'FATOU BAH']);
        $this->assertSame(Command::SUCCESS, $this->lancer(['--activer' => true]));

        $this->assertNotNull($this->org->fresh()->synchronisation_identite_client_le);
        $this->assertSame('Fatou Bah', $c->fresh()->nom_complet);
        $this->assertSame(Command::INVALID, $this->lancer(['--activer' => true]));
    }

    public function test_un_client_sans_les_droits_ne_peut_ni_appliquer_ni_activer(): void
    {
        $sansDroit = $this->utilisateur(['contacts.read']);

        $this->artisan(self::COMMANDE, ['--acteur' => $sansDroit->id, '--apply' => true])->assertExitCode(Command::INVALID);
        $this->artisan(self::COMMANDE, ['--acteur' => $sansDroit->id, '--activer' => true])->assertExitCode(Command::INVALID);
    }

    // ── Création d'un client (R1 active ou non) ─────────────────────────────

    private function donneesClient(array $surcharges = []): array
    {
        // Sans e-mail : la validation du formulaire Client vérifie le DNS du domaine.
        return ['nom_complet' => 'Nom saisi', 'telephone' => '622000001', 'code_pays' => 'GN', 'adresse' => 'Quartier Dixinn', 'ville' => 'Boké', ...$surcharges];
    }

    public function test_sans_activation_un_client_garde_sa_saisie(): void
    {
        $this->personne(['nom_complet' => 'Fatou Bah', 'telephone' => '+224622000001']);

        $this->actingAs($this->utilisateur())->post(route('clients.store'), $this->donneesClient())->assertSessionHasNoErrors();

        $this->assertSame('Nom Saisi', Client::firstOrFail()->nom_complet);
    }

    public function test_apres_activation_la_personne_existante_fait_foi_et_ses_champs_vides_sont_completes(): void
    {
        $p = $this->personne(['nom_complet' => 'Fatou Bah', 'telephone' => '+224622000001']);
        $this->org->forceFill(['synchronisation_identite_client_le' => now()])->save();

        $this->actingAs($this->utilisateur())->post(route('clients.store'), $this->donneesClient())->assertSessionHasNoErrors();

        $client = Client::firstOrFail();
        $this->assertSame('Fatou Bah', $client->nom_complet);
        $this->assertSame('Quartier Dixinn', $p->fresh()->adresse);
        $this->assertSame('Quartier Dixinn', $client->adresse);
        $this->assertSame('Boké', $client->ville);
    }

    // ── Modification de l'identité depuis Contacts ──────────────────────────

    public function test_modifier_l_identite_d_une_personne_depuis_contacts(): void
    {
        $p = $this->personne(['nom_complet' => 'Fatou Bah', 'telephone' => '+224622000001', 'ville' => 'Conakry']);
        $c = $this->client($p);
        $this->org->forceFill(['synchronisation_identite_client_le' => now()])->save();
        $user = $this->utilisateur();

        $this->actingAs($user)->patch(route('contacts.personnes.update', $p), [
            'nom_complet' => 'Fatoumata Bah', 'telephone' => '+224622000001', 'ville' => 'Kindia',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Fatoumata Bah', $p->fresh()->getAttributes()['nom_complet']);
        // Synchronisation active : le client suit sa fiche.
        $this->assertSame('Fatoumata Bah', $c->fresh()->nom_complet);
        $this->assertSame('Kindia', $c->fresh()->ville);
        $this->assertTrue(AuditLog::where('auditable_id', $p->id)->where('meta->origine', 'contacts')->exists());
    }

    public function test_modifier_l_identite_exige_contacts_update_et_une_fiche_visible(): void
    {
        $p = $this->personne(['nom_complet' => 'Fatou Bah', 'telephone' => '+224622000001', 'ville' => 'Conakry']);
        $this->client($p);
        $donnees = ['nom_complet' => 'Autre', 'telephone' => '+224622000001', 'ville' => 'Conakry'];

        $this->actingAs($this->utilisateur(['clients.read', 'contacts.read']))->patch(route('contacts.personnes.update', $p), $donnees)->assertForbidden();
        $this->actingAs($this->utilisateur(['contacts.read', 'contacts.update']))->patch(route('contacts.personnes.update', $p), $donnees)->assertNotFound();
        $this->assertSame('Fatou Bah', $p->fresh()->getAttributes()['nom_complet']);
    }

    public function test_un_numero_d_une_fiche_non_visible_recoit_le_message_neutre(): void
    {
        $p = $this->personne(['nom_complet' => 'Fatou Bah', 'telephone' => '+224622000001', 'ville' => 'Conakry']);
        $this->client($p);
        $cachee = $this->personne(['telephone' => '+224622000077']);
        Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $cachee->id]);

        $this->actingAs($this->utilisateur())->patch(route('contacts.personnes.update', $p), [
            'nom_complet' => 'Fatou Bah', 'telephone' => '+224622000077', 'ville' => 'Conakry',
        ])->assertSessionHasErrors(['telephone' => MessagesContacts::NUMERO_NON_VISIBLE]);
    }

    public function test_renommer_une_entreprise_depuis_contacts_se_repercute_sur_son_proprietaire(): void
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Kaloum SA']);
        $proprietaire = Proprietaire::factory()->entreprise('Kaloum SA')->create(['organization_id' => $this->org->id]);
        $proprietaire->forceFill(['entreprise_tierce_id' => $entreprise->id])->save();
        $user = $this->utilisateur(['contacts.read', 'contacts.update', 'proprietaires.read']);

        $this->actingAs($user)->patch(route('contacts.entreprises.update', $entreprise), ['raison_sociale' => 'Kaloum Transports', 'nom_commercial' => 'KT'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Kaloum Transports', $entreprise->fresh()->raison_sociale);
        $this->assertNull($entreprise->fresh()->telephone);
        $this->assertSame('Kaloum Transports', $proprietaire->fresh()->raison_sociale);
    }
}
