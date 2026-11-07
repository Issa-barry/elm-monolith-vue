<?php

namespace Tests\Feature\Contacts;

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Personne;
use App\Services\Contacts\ClientIdentiteSynchronisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Synchronisation Personne → Client (R1, ADR 0060). Le service est construit et testé mais
 * NON BRANCHÉ : le dernier test garantit qu'aucun point d'écriture existant ne l'appelle avant le
 * lot 7 (arbitrage des écarts, D16).
 */
class ClientIdentiteSynchronisationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private ClientIdentiteSynchronisation $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->service = app(ClientIdentiteSynchronisation::class);
    }

    private function client(Personne $personne, array $attributs = []): Client
    {
        return Client::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Ancien nom',
            'telephone' => '+224600000000',
            'type' => ClientType::GROSSISTE->value,
            'cashback_eligible' => true,
            'cashback_montant_par_pack' => 50,
            'derogation_impayes_autorisee' => true,
            'seuil_derogation_impayes' => 100000,
            ...$attributs,
        ]);
    }

    public function test_l_identite_de_la_personne_est_recopiee_sur_ses_clients(): void
    {
        $personne = Personne::factory()->create([
            'organization_id' => $this->org->id, 'nom_complet' => 'Fatou Bah', 'telephone' => '+224622123456',
            'email' => 'fatou@exemple.gn', 'ville' => 'Conakry', 'adresse' => 'Kaloum', 'pays' => 'Guinée', 'code_pays' => 'GN', 'code_phone_pays' => '+224',
        ]);
        $client = $this->client($personne);

        $this->assertSame(1, $this->service->depuisPersonne($personne));

        $client->refresh();
        $this->assertSame('Fatou Bah', $client->nom_complet);
        $this->assertSame('+224622123456', $client->telephone);
        $this->assertSame('fatou@exemple.gn', $client->email);
        $this->assertSame('Conakry', $client->ville);
        $this->assertSame('Kaloum', $client->adresse);
        $this->assertSame('GN', $client->code_pays);
    }

    public function test_les_donnees_propres_a_la_fonction_client_ne_sont_jamais_touchees(): void
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);
        $client = $this->client($personne);

        $this->service->depuisPersonne($personne);

        $client->refresh();
        $this->assertSame(ClientType::GROSSISTE, $client->type);
        $this->assertTrue($client->cashback_eligible);
        $this->assertSame(50, $client->cashback_montant_par_pack);
        $this->assertTrue($client->derogation_impayes_autorisee);
        $this->assertSame(100000, $client->seuil_derogation_impayes);
        $this->assertSame($personne->id, $client->personne_id);
    }

    public function test_rien_n_est_ecrit_si_l_identite_est_deja_identique(): void
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);
        $this->client($personne);
        $this->service->depuisPersonne($personne);

        $this->assertSame(0, $this->service->depuisPersonne($personne->fresh()));
    }

    public function test_un_conflit_d_unicite_refuse_tout_sans_ecriture_partielle(): void
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id, 'email' => 'pris@exemple.gn']);
        $premier = $this->client($personne, ['telephone' => '+224600000001']);
        $second = $this->client($personne, ['telephone' => '+224600000002']);
        Client::create(['organization_id' => $this->org->id, 'nom_complet' => 'Autre', 'telephone' => '+224699999999', 'email' => 'pris@exemple.gn', 'type' => ClientType::EXTERNE->value]);

        try {
            $this->service->depuisPersonne($personne);
            $this->fail('Conflit d\'e-mail accepté.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        $this->assertSame('Ancien nom', $premier->fresh()->nom_complet);
        $this->assertSame('Ancien nom', $second->fresh()->nom_complet);
    }

    public function test_les_clients_d_une_autre_organisation_ne_sont_jamais_modifies(): void
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id, 'nom_complet' => 'Fatou Bah']);
        $ailleurs = Client::create(['organization_id' => Organization::factory()->create()->id, 'personne_id' => $personne->id, 'nom_complet' => 'Intact', 'telephone' => '+224600000003', 'type' => ClientType::EXTERNE->value]);

        $this->service->depuisPersonne($personne);

        $this->assertSame('Intact', $ailleurs->fresh()->nom_complet);
    }

    public function test_le_service_n_est_branche_a_aucun_point_d_ecriture(): void
    {
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        $appelants = collect(iterator_to_array($fichiers, false))
            ->map(fn (\SplFileInfo $f) => $f->getPathname())
            ->filter(fn (string $fichier) => str_ends_with($fichier, '.php'))
            ->reject(fn (string $fichier) => str_ends_with($fichier, 'ClientIdentiteSynchronisation.php'))
            ->filter(fn (string $fichier) => str_contains((string) file_get_contents($fichier), 'ClientIdentiteSynchronisation'))
            ->map(fn (string $fichier) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $fichier))
            ->values()
            ->all();

        $this->assertSame([], $appelants, 'La synchronisation R1 ne se branche qu\'au lot 7, après l\'arbitrage des écarts (D16).');
    }
}
