<?php

namespace Tests\Feature\Api\Client;

use App\Enums\StatutPropositionVehicule;
use App\Models\Organization;
use App\Models\PropositionVehicule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Vérifie que l'API expose exactement la même logique que
 * ClientDashboardController::storeVehicleProposal() (Inertia) — les deux
 * appellent VehicleProposalService, jamais un moteur dupliqué.
 */
class PropositionsVehiculeControllerTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    public function test_proprietaire_can_submit_a_vehicle_proposal(): void
    {
        Storage::fake('public');
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson(route('client.propositions-vehicules.store'), [
            'nom_vehicule' => 'Camion Partenaire',
            'immatriculation' => 'rc-010-gn',
            'type_vehicule' => 'camion',
            'commentaire' => 'Disponible immediatement.',
            'photo' => UploadedFile::fake()->image('vehicule.jpg'),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.immatriculation', 'RC-010-GN');
        $response->assertJsonPath('data.statut', 'pending');

        $this->assertDatabaseHas('propositions_vehicules', [
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'proprietaire_id' => $user->proprietaire->id,
            'immatriculation' => 'RC-010-GN',
            'statut' => 'pending',
        ]);
    }

    public function test_rejects_duplicate_pending_immatriculation_with_422(): void
    {
        Storage::fake('public');
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $payload = [
            'immatriculation' => 'RC-011-GN',
            'type_vehicule' => 'camion',
        ];

        $this->postJson(route('client.propositions-vehicules.store'), $payload + ['photo' => UploadedFile::fake()->image('a.jpg')])
            ->assertStatus(201);

        $this->postJson(route('client.propositions-vehicules.store'), $payload + ['photo' => UploadedFile::fake()->image('b.jpg')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('immatriculation');

        $this->assertSame(1, PropositionVehicule::where('immatriculation', 'RC-011-GN')->count());
    }

    public function test_validates_required_fields(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->postJson(route('client.propositions-vehicules.store'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['immatriculation', 'type_vehicule', 'photo']);
    }

    public function test_lists_only_the_authenticated_users_proposals(): void
    {
        Storage::fake('public');
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $other = $this->makeProprietaireUser($org);

        PropositionVehicule::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'immatriculation' => 'RC-020-GN',
            'type_vehicule' => 'camion',
            'photo_path' => 'x.webp',
            'statut' => StatutPropositionVehicule::PENDING->value,
        ]);
        PropositionVehicule::create([
            'organization_id' => $org->id,
            'user_id' => $other->id,
            'immatriculation' => 'RC-021-GN',
            'type_vehicule' => 'camion',
            'photo_path' => 'y.webp',
            'statut' => StatutPropositionVehicule::PENDING->value,
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.propositions-vehicules.index'))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('RC-020-GN', $response->json('data.0.immatriculation'));
    }

    public function test_forbidden_for_staff_only_account(): void
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.propositions-vehicules.index'))->assertStatus(403);
        $this->postJson(route('client.propositions-vehicules.store'), [])->assertStatus(403);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(route('client.propositions-vehicules.index'))->assertStatus(401);
    }
}
