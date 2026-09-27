<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class ClientIndexFiltersTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['clients.read']);
        $this->actingAs($this->user);
    }

    public function test_each_nature_is_filtered_on_the_server(): void
    {
        $clients = [];
        foreach (ClientType::cases() as $type) {
            $clients[$type->value] = Client::factory()->create([
                'organization_id' => $this->org->id,
                'type' => $type,
            ]);
        }

        foreach ($clients as $type => $client) {
            $this->get(route('clients.index', ['type' => $type]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Clients/Index')
                    ->has('clients', 1)
                    ->where('clients.0.id', $client->id)
                    ->where('filters.type', $type)
                    ->has('types', count(ClientType::cases())));
        }
    }

    public function test_cashback_eligibility_is_independent_of_nature_and_scoped_to_the_organization(): void
    {
        $eligible = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ClientType::GROSSISTE,
            'cashback_eligible' => true,
        ]);
        $nonEligible = Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ClientType::GROSSISTE,
            'cashback_eligible' => false,
            'cashback_montant_par_pack' => null,
        ]);
        Client::factory()->create(['organization_id' => Organization::factory(), 'cashback_eligible' => true]);

        foreach (['eligible' => $eligible, 'non_eligible' => $nonEligible] as $filtre => $client) {
            $this->get(route('clients.index', ['cashback' => $filtre]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('clients', 1)
                    ->where('clients.0.id', $client->id)
                    ->where('filters.cashback', $filtre));
        }
    }

    public function test_filters_combine_and_a_bare_url_restores_all_clients_in_the_organization(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'nom_complet' => 'Fatoumata Diallo',
            'type' => ClientType::DISTRIBUTEUR,
            'cashback_eligible' => false,
            'cashback_montant_par_pack' => null,
            'is_active' => false,
        ]);
        Client::factory()->create(['organization_id' => $this->org->id, 'nom_complet' => 'Fatoumata Diallo']);
        Client::factory()->create(['organization_id' => Organization::factory(), 'nom_complet' => 'Fatoumata Diallo']);

        $filters = ['type' => 'distributeur', 'cashback' => 'non_eligible', 'statut' => 'inactif', 'recherche' => 'Fatoumata Diallo'];
        $this->get(route('clients.index', $filters))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients', 1)
                ->where('clients.0.id', $client->id)
                ->where('filters', $filters));

        $this->get(route('clients.index'))->assertInertia(fn (Assert $page) => $page
            ->has('clients', 2)
            ->where('filters.type', '')
            ->where('filters.cashback', '')
            ->where('filters.statut', '')
            ->where('filters.recherche', ''));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->getJson(route('clients.index', [
            'type' => 'inconnu',
            'cashback' => 'inconnu',
            'statut' => 'inconnu',
            'recherche' => ['invalid'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['type', 'cashback', 'statut', 'recherche']);
    }
}
