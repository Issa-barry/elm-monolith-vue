<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Http\Controllers\Client\ClientDashboardController;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\PropositionVehicule;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function clientUser(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000001',
        ]);
        $user->assignRole('client');

        return $user;
    }

    private function staffUser(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
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

        Storage::fake('public');

        $this->actingAs($user)
            ->post(route('client.propositions.store'), [
                'nom_vehicule' => 'Camion Partenaire',
                'immatriculation' => 'rc-001-gn',
                'type_vehicule' => 'camion',
                'capacite_packs' => 180,
                'commentaire' => 'Disponible immediatement.',
                'photo' => UploadedFile::fake()->image('vehicule.jpg'),
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

    /**
     * Régression : le contrôle anti-doublon (DuplicateVehicleProposalException,
     * cf. VehicleProposalService) passait auparavant par un `if` inline dans
     * ClientDashboardController — jamais couvert par un test avant l'extraction
     * du 26/08/2026 vers le service partagé.
     */
    public function test_store_vehicle_proposal_rejects_duplicate_pending_immatriculation(): void
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

        Storage::fake('public');

        $submit = fn () => $this->actingAs($user)->post(route('client.propositions.store'), [
            'immatriculation' => 'RC-002-GN',
            'type_vehicule' => 'camion',
            'photo' => UploadedFile::fake()->image('vehicule.jpg'),
        ]);

        $submit()->assertRedirect(route('client.propositions.index'));
        $submit()->assertSessionHasErrors('immatriculation');

        $this->assertSame(1, PropositionVehicule::where('immatriculation', 'RC-002-GN')->count());
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

        $personne = Personne::create([
            'organization_id' => $org->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'telephone' => $user->telephone,
        ]);

        $proprietaire = Proprietaire::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'personne_id' => $personne->id,
            'is_active' => true,
        ]);

        $vehicule = Vehicule::create([
            'organization_id' => $org->id,
            'nom_vehicule' => 'Vehicule Test',
            'immatriculation' => 'AA-123-GN',
            'type_vehicule' => 'camion',
            'capacite_packs' => 120,
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'partenaire',
            'livraison_vente' => true,
            'is_active' => true,
        ]);

        $commande = CommandeVente::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => 100000,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS->value,
            'validated_at' => now(),
        ]);

        $processus = CommissionProcessus::create([
            'organization_id' => $org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => 'operation',
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        $commission = CommissionEnveloppe::create([
            'organization_id' => $org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 15000,
            'earned_at' => now(),
            'statut' => StatutCommission::PARTIEL->value,
        ]);

        CommissionEnveloppePart::create([
            'enveloppe_id' => $commission->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PROPRIETAIRE,
            'beneficiaire_id' => $proprietaire->id,
            'montant_brut' => 15000,
            'montant_net' => 15000,
            'montant_verse' => 3000,
            'statut' => StatutCommission::PARTIEL->value,
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

    /**
     * Régression : `earningsByVehicule()` lisait `$part->commission?->vehicule` pour les
     * parts de VENTE (CommissionEnveloppePart), qui n'a pourtant aucune relation `commission`
     * (seulement `enveloppe`) — cet appel renvoyait toujours null et excluait silencieusement
     * toute commission de vente du détail "solde par véhicule" (cf. docblock de
     * ClientEarningsService::earningsByVehicule()). Seuls les totaux agrégés étaient
     * couverts par `test_dashboard_exposes_partner_earnings_for_owner`, jamais ce détail.
     */
    public function test_dashboard_exposes_vente_commission_in_earnings_by_vehicule(): void
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

        $personne = Personne::create([
            'organization_id' => $org->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'telephone' => $user->telephone,
        ]);

        $proprietaire = Proprietaire::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'personne_id' => $personne->id,
            'is_active' => true,
        ]);

        $vehicule = Vehicule::create([
            'organization_id' => $org->id,
            'nom_vehicule' => 'Vehicule Vente',
            'immatriculation' => 'CC-789-GN',
            'type_vehicule' => 'camion',
            'capacite_packs' => 120,
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'partenaire',
            'livraison_vente' => true,
            'is_active' => true,
        ]);

        $commande = CommandeVente::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => 100000,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS->value,
            'validated_at' => now(),
        ]);

        $processus = CommissionProcessus::create([
            'organization_id' => $org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => 'operation',
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        $commission = CommissionEnveloppe::create([
            'organization_id' => $org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 15000,
            'earned_at' => now(),
            'statut' => StatutCommission::PARTIEL->value,
        ]);

        CommissionEnveloppePart::create([
            'enveloppe_id' => $commission->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PROPRIETAIRE,
            'beneficiaire_id' => $proprietaire->id,
            'montant_brut' => 15000,
            'montant_net' => 15000,
            'montant_verse' => 3000,
            'statut' => StatutCommission::PARTIEL->value,
        ]);

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/Dashboard')
                ->where('earnings_by_vehicule.0.vehicule_id', $vehicule->id)
                ->where('earnings_by_vehicule.0.total_earned', 15000)
                ->where('earnings_by_vehicule.0.total_paid', 3000)
                ->where('earnings_by_vehicule.0.balance', 12000)
            );

        // Second bug composé, découvert en même temps : l'eager-load de partsVentes()
        // ne chargeait pas `vehicule_id` sur `source`, donc `releve()` (exposé ici en
        // tant que `statement`) retombait aussi sur '-' pour le véhicule d'une vente.
        $this->actingAs($user)
            ->get(route('client.earnings'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/Earnings')
                ->where('statement.0.vehicule_id', $vehicule->id)
                ->where('statement.0.vehicule_nom', 'Vehicule Vente')
            );
    }

    /**
     * Cas non couvert par les autres tests : le Proprietaire n'a pas de `user_id` (compte
     * client créé séparément, jamais explicitement lié), la seule correspondance possible
     * passe par le téléphone — via `Personne::telephone`, plus une colonne de `proprietaires`
     * depuis la refonte PERSONNE + USERS. Régression réelle : cassait uniquement sur MySQL/E2E,
     * pas sur SQLite (tests), qui tolère silencieusement un `WHERE` sur colonne inexistante.
     */
    public function test_dashboard_resolves_proprietaire_by_telephone_when_not_linked_by_user_id(): void
    {
        $org = Organization::factory()->create();
        $user = $this->clientUser($org);

        $personne = Personne::create([
            'organization_id' => $org->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'telephone' => $user->telephone,
        ]);

        $proprietaire = Proprietaire::create([
            'organization_id' => $org->id,
            'user_id' => null,
            'personne_id' => $personne->id,
            'is_active' => true,
        ]);

        $vehicule = Vehicule::create([
            'organization_id' => $org->id,
            'nom_vehicule' => 'Vehicule Sans Lien User',
            'immatriculation' => 'BB-456-GN',
            'type_vehicule' => 'camion',
            'capacite_packs' => 120,
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'partenaire',
            'livraison_vente' => true,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/Dashboard')
                ->where('actor.is_partner', true)
                ->where('actor.proprietaire_id', $proprietaire->id)
                ->where('vehicules.0.nom_vehicule', $vehicule->nom_vehicule)
            );

        $this->actingAs($user)
            ->get(route('client.propositions.index'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/VehicleProposals')
                ->where('actor.is_partner', true)
            );
    }

    public function test_qr_code_returns_svg_for_authenticated_user(): void
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
            ->get(route('client.qr-code'))
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8');
    }

    public function test_qr_payload_is_proprietaire_fiche_url_for_owner(): void
    {
        $org = Organization::factory()->create();
        $user = $this->clientUser($org);

        $personne = Personne::create([
            'organization_id' => $org->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'telephone' => $user->telephone,
        ]);

        $proprietaire = Proprietaire::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'personne_id' => $personne->id,
            'is_active' => true,
        ]);

        $controller = app(ClientDashboardController::class);
        $method = new \ReflectionMethod($controller, 'resolveQrPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($controller, $user);

        $this->assertSame(route('proprietaires.show', $proprietaire->id), $payload);
    }

    public function test_qr_payload_is_livreur_commissions_url_for_livreur(): void
    {
        Role::firstOrCreate(['name' => 'livreur', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224620000010',
        ]);
        $user->assignRole('livreur');

        $personne = Personne::create([
            'organization_id' => $org->id,
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'telephone' => $user->telephone,
        ]);

        $livreur = Livreur::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'personne_id' => $personne->id,
            'is_active' => true,
        ]);

        $controller = app(ClientDashboardController::class);
        $method = new \ReflectionMethod($controller, 'resolveQrPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($controller, $user);

        $this->assertSame(route('livreurs.show', $livreur->id), $payload);
    }

    public function test_qr_payload_is_dashboard_url_for_non_owner(): void
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

        $controller = app(ClientDashboardController::class);
        $method = new \ReflectionMethod($controller, 'resolveQrPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($controller, $user);

        $this->assertSame(route('dashboard'), $payload);
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
