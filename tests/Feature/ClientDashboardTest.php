<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function clientUser(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000001',
        ]);
        $user->assignRole('client');

        return $user;
    }

    private function staffUser(Organization $org): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        return $user;
    }

    public function test_index_returns_200_for_client_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->clientUser($org);

        Client::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'telephone' => $user->telephone,
        ]);

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('client.dashboard'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_for_staff_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->staffUser($org);

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertStatus(403);
    }

    public function test_client_can_store_vehicle_proposal(): void
    {
        $org = Organization::factory()->create();
        $user = $this->clientUser($org);

        Client::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'telephone' => $user->telephone,
        ]);

        $this->actingAs($user)
            ->post(route('client.propositions.store'), [
                'nom_vehicule' => 'Camion Partenaire',
                'immatriculation' => 'rc-001-gn',
                'type_vehicule' => 'camion',
                'capacite_packs' => 180,
                'commentaire' => 'Disponible immediatement.',
            ])
            ->assertRedirect(route('client.propositions.index'));

        $this->assertDatabaseHas('propositions_vehicules', [
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'nom_vehicule' => 'Camion Partenaire',
            'immatriculation' => 'RC-001-GN',
            'type_vehicule' => 'camion',
            'statut' => 'pending',
        ]);
    }

    public function test_dashboard_exposes_partner_earnings_for_owner(): void
    {
        $org = Organization::factory()->create();
        $user = $this->clientUser($org);

        Client::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'telephone' => $user->telephone,
        ]);

        $proprietaire = Proprietaire::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'telephone' => $user->telephone,
            'is_active' => true,
        ]);

        $vehicule = Vehicule::create([
            'organization_id' => $org->id,
            'nom_vehicule' => 'Vehicule Test',
            'immatriculation' => 'AA-123-GN',
            'type_vehicule' => 'camion',
            'categorie' => 'externe',
            'capacite_packs' => 120,
            'proprietaire_id' => $proprietaire->id,
            'pris_en_charge_par_usine' => false,
            'is_active' => true,
        ]);

        $commande = CommandeVente::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => 100000,
            'statut' => StatutCommandeVente::EN_COURS->value,
            'validated_at' => now(),
        ]);

        $commission = CommissionVente::create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'vehicule_id' => $vehicule->id,
            'montant_commande' => 100000,
            'montant_commission_totale' => 15000,
            'montant_verse' => 3000,
            'statut' => StatutCommission::PARTIELLE->value,
        ]);

        CommissionPart::create([
            'commission_vente_id' => $commission->id,
            'type_beneficiaire' => 'proprietaire',
            'proprietaire_id' => $proprietaire->id,
            'beneficiaire_nom' => trim($proprietaire->prenom.' '.$proprietaire->nom),
            'taux_commission' => 100,
            'montant_brut' => 15000,
            'montant_net' => 15000,
            'montant_verse' => 3000,
            'statut' => StatutCommission::PARTIELLE->value,
        ]);

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/Dashboard')
                ->where('actor.is_partner', true)
                ->where('earnings.total_earned', 15000)
                ->where('earnings.total_paid', 3000)
                ->where('earnings.balance', 12000)
                ->where('vehicules.0.nom_vehicule', 'Vehicule Test')
            );
    }

    public function test_client_menu_pages_are_accessible(): void
    {
        $org = Organization::factory()->create();
        $user = $this->clientUser($org);

        Client::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'telephone' => $user->telephone,
        ]);

        $this->actingAs($user)
            ->get(route('client.vehicles'))
            ->assertStatus(200);

        $this->actingAs($user)
            ->get(route('client.propositions.index'))
            ->assertStatus(200);

        $this->actingAs($user)
            ->get(route('client.earnings'))
            ->assertStatus(200);

        $this->actingAs($user)
            ->get(route('client.profile'))
            ->assertStatus(200);
    }
}
