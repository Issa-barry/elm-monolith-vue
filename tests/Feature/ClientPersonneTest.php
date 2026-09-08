<?php

namespace Tests\Feature;

use App\DTOs\RegisterData;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Client comme rôle porté par Personne (cf. docs/identite-client-personne.md) — couvre
 * exactement les cas de données demandés lors de l'analyse d'impact : résolution/réutilisation
 * d'identité, isolation organisationnelle, backfill des clients existants, et le cas réel
 * `666177001` (client Guirrasy) qui avait révélé l'absence de lien Client -> Personne.
 */
class ClientPersonneTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['clients.read', 'clients.create', 'clients.update', 'clients.delete', 'vehicules.read', 'vehicules.create', 'vehicules.update']);
    }

    private function runBackfillMigration(): void
    {
        $migration = include database_path('migrations/2026_09_08_090001_backfill_personne_id_on_clients_table.php');
        $migration->up();
    }

    // ── store() : résolution/création de Personne ───────────────────────────────

    public function test_store_cree_une_nouvelle_personne_pour_un_telephone_inconnu(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom_complet' => 'Guirrasy',
                'telephone' => '666177001',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'type' => 'externe',
            ])
            ->assertRedirect();

        $client = Client::where('organization_id', $this->org->id)->first();
        $this->assertNotNull($client->personne_id);
        $this->assertDatabaseHas('personnes', [
            'id' => $client->personne_id,
            'organization_id' => $this->org->id,
            'telephone_normalise' => '224666177001',
        ]);
    }

    public function test_store_reutilise_la_personne_dun_role_existant_partageant_le_telephone(): void
    {
        $proprietaire = Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'telephone' => '+224622000001',
        ]);

        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'nom_complet' => 'Nom Saisi Par Le Client',
                'telephone' => '622000001',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'type' => 'externe',
            ])
            ->assertRedirect();

        $client = Client::where('organization_id', $this->org->id)->first();
        $this->assertSame($proprietaire->personne_id, $client->personne_id);
        // La résolution ne doit jamais écraser l'identité déjà connue de la Personne.
        $this->assertSame('Mamadou Diallo', $proprietaire->personne->fresh()->nom_complet);
    }

    public function test_deux_organisations_avec_le_meme_telephone_ont_deux_personnes_distinctes(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreUser = $this->makeUserWithPermissions($autreOrg, ['clients.create']);
        $this->attachDefaultSite($autreOrg, $autreUser);

        $this->actingAs($this->user)->post(route('clients.store'), [
            'nom_complet' => 'Client Org A',
            'telephone' => '666177001',
            'code_pays' => 'GN',
            'ville' => 'Conakry',
            'type' => 'externe',
        ])->assertRedirect();

        $this->actingAs($autreUser)->post(route('clients.store'), [
            'nom_complet' => 'Client Org B',
            'telephone' => '666177001',
            'code_pays' => 'GN',
            'ville' => 'Conakry',
            'type' => 'externe',
        ])->assertRedirect();

        $clientA = Client::where('organization_id', $this->org->id)->first();
        $clientB = Client::where('organization_id', $autreOrg->id)->first();

        $this->assertNotSame($clientA->personne_id, $clientB->personne_id);
        $this->assertSame($this->org->id, $clientA->personne->organization_id);
        $this->assertSame($autreOrg->id, $clientB->personne->organization_id);
    }

    // ── update() : édition en place, jamais de re-résolution ────────────────────

    public function test_update_modifie_la_personne_liee_en_place(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224666177001',
        ]);
        $personne = Personne::resoudreOuCreer($this->org->id, [
            'nom_complet' => $client->nom_complet,
            'telephone' => $client->telephone,
        ]);
        $client->update(['personne_id' => $personne->id]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom_complet' => 'Guirrasy Modifie',
                'telephone' => '666177001',
                'code_pays' => 'GN',
                'ville' => 'Kindia',
                'type' => $client->type->value,
            ])
            ->assertRedirect();

        $this->assertSame('Guirrasy Modifie', $personne->fresh()->nom_complet);
        $this->assertSame('Kindia', $personne->fresh()->ville);
    }

    public function test_update_refuse_un_telephone_deja_utilise_par_une_autre_personne(): void
    {
        Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000002',
        ]);
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224666177001',
        ]);
        $personne = Personne::resoudreOuCreer($this->org->id, ['telephone' => $client->telephone]);
        $client->update(['personne_id' => $personne->id]);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom_complet' => $client->nom_complet,
                'telephone' => '622000002',
                'code_pays' => 'GN',
                'ville' => 'Conakry',
                'type' => $client->type->value,
            ])
            ->assertSessionHasErrors('telephone');
    }

    // ── Backfill des clients existants ───────────────────────────────────────────

    public function test_backfill_rattache_un_client_existant_sans_creer_de_doublon(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'nom_complet' => 'Guirrasy',
            'telephone' => '+224666177001',
        ]);
        $this->assertNull($client->personne_id);

        $this->runBackfillMigration();

        $client->refresh();
        $this->assertNotNull($client->personne_id);
        $this->assertSame(1, Personne::where('organization_id', $this->org->id)
            ->where('telephone_normalise', '224666177001')
            ->count());
    }

    public function test_backfill_unifie_deux_clients_de_la_meme_organisation_partageant_un_telephone(): void
    {
        // Simule une donnée historique antérieure à la contrainte d'unicité applicative —
        // jamais possible via ClientController::store() aujourd'hui, mais doit être géré
        // proprement si elle existe déjà en base au moment du backfill.
        $clientA = Client::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224666177001',
        ]);
        $clientB = Client::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224666177001',
        ]);

        $this->runBackfillMigration();

        $clientA->refresh();
        $clientB->refresh();
        $this->assertNotNull($clientA->personne_id);
        $this->assertSame($clientA->personne_id, $clientB->personne_id);
    }

    public function test_backfill_reutilise_la_personne_dun_role_existant(): void
    {
        $proprietaire = Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224666177001',
        ]);
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224666177001',
        ]);

        $this->runBackfillMigration();

        $client->refresh();
        $this->assertSame($proprietaire->personne_id, $client->personne_id);
    }

    // ── Cas réel : 666177001 (Guirrasy) sous toutes ses formes ──────────────────

    public function test_le_cas_reel_666177001_est_retrouve_sous_toutes_ses_formes_apres_backfill(): void
    {
        Client::factory()->create([
            'organization_id' => $this->org->id,
            'nom_complet' => 'Guirrasy',
            'telephone' => '+224666177001',
        ]);
        $this->runBackfillMigration();

        foreach (['666177001', '+224666177001', '+224 666 17 70 01'] as $saisie) {
            $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);

            $response = $this->actingAs($this->user)
                ->getJson(route('vehicules.parrain.rechercher', $vehicule).'?telephone='.urlencode($saisie).'&code_pays=GN')
                ->assertStatus(200);

            $response->assertJson(['found' => true, 'personne' => ['nom_complet' => 'Guirrasy']]);
        }
    }

    // ── Auto-inscription : le nouveau compte doit rejoindre la Personne du client existant ──

    public function test_auto_inscription_mobile_rattache_le_compte_a_la_personne_du_client_existant(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224666177001',
            'user_id' => null,
        ]);
        $personne = Personne::resoudreOuCreer($this->org->id, ['telephone' => $client->telephone]);
        $client->update(['personne_id' => $personne->id]);

        $user = app(RegistrationService::class)->register(new RegisterData(
            nom: 'Guirrasy',
            prenom: 'Client',
            telephone: '+224666177001',
            email: null,
            password: 'Password@123',
        ));

        $this->assertSame($personne->id, $user->fresh()->personne_id);
        $this->assertSame($this->org->id, $user->fresh()->organization_id);
        // La Personne "à la volée" créée en tête de register() ne doit pas persister en doublon.
        $this->assertSame(1, Personne::where('telephone_normalise', '224666177001')->count());
    }
}
