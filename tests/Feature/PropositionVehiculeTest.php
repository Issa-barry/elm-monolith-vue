<?php

namespace Tests\Feature;

use App\Enums\StatutPropositionVehicule;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\PropositionVehicule;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class PropositionVehiculeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['propositions.read', 'propositions.update']);
    }

    private function makeProposition(array $overrides = []): PropositionVehicule
    {
        return PropositionVehicule::create(array_merge([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'nom_contact' => 'Mamadou Diallo',
            'telephone_contact' => '+224620123456',
            'nom_vehicule' => 'Camion Test',
            'immatriculation' => 'RC-001-GN',
            'type_vehicule' => 'camion',
            'capacite_packs' => 150,
            'statut' => StatutPropositionVehicule::SOUMISE->value,
        ], $overrides));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('propositions-vehicules.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('propositions-vehicules.index'))
            ->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('propositions-vehicules.index'))
            ->assertStatus(403);
    }

    public function test_index_only_shows_own_org_propositions(): void
    {
        $this->makeProposition(['nom_vehicule' => 'Camion Org']);

        $otherOrg = Organization::factory()->create();
        PropositionVehicule::create([
            'organization_id' => $otherOrg->id,
            'nom_contact' => 'Autre Contact',
            'immatriculation' => 'OT-999-GN',
            'type_vehicule' => 'moto',
            'statut' => StatutPropositionVehicule::SOUMISE->value,
        ]);

        $this->actingAs($this->user)
            ->get(route('propositions-vehicules.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Vehicules/Propositions/Index')
                ->where('propositions.0.nom_vehicule', 'Camion Org')
                ->count('propositions', 1)
            );
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_200_for_authorized_user(): void
    {
        $proposition = $this->makeProposition();

        $this->actingAs($this->user)
            ->get(route('propositions-vehicules.show', $proposition))
            ->assertStatus(200);
    }

    public function test_show_returns_403_without_permission(): void
    {
        $proposition = $this->makeProposition();
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('propositions-vehicules.show', $proposition))
            ->assertStatus(403);
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $proposition = PropositionVehicule::create([
            'organization_id' => $otherOrg->id,
            'nom_contact' => 'Alpha Barry',
            'telephone_contact' => '+224620999999',
            'nom_vehicule' => 'Camion Autre',
            'immatriculation' => 'RA-999-GN',
            'type_vehicule' => 'camion',
            'statut' => StatutPropositionVehicule::SOUMISE->value,
        ]);

        $this->actingAs($this->user)
            ->get(route('propositions-vehicules.show', $proposition))
            ->assertStatus(403);
    }

    public function test_show_exposes_doublon_alert_when_immatriculation_exists(): void
    {
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'RC-001-GN',
        ]);

        $proposition = $this->makeProposition(['immatriculation' => 'rc-001-gn']);

        $this->actingAs($this->user)
            ->get(route('propositions-vehicules.show', $proposition))
            ->assertInertia(fn ($page) => $page
                ->component('Vehicules/Propositions/Show')
                ->whereNot('vehicule_doublon', null)
            );
    }

    // ── priseEnCharge ─────────────────────────────────────────────────────────

    public function test_prise_en_charge_changes_statut_to_en_revision(): void
    {
        $proposition = $this->makeProposition(['statut' => StatutPropositionVehicule::SOUMISE->value]);

        $this->actingAs($this->user)
            ->patch(route('propositions-vehicules.prendre-en-charge', $proposition))
            ->assertRedirect();

        $this->assertDatabaseHas('propositions_vehicules', [
            'id' => $proposition->id,
            'statut' => StatutPropositionVehicule::EN_REVISION->value,
            'traitee_par' => $this->user->id,
        ]);
    }

    public function test_prise_en_charge_returns_403_for_terminal_proposition(): void
    {
        $proposition = $this->makeProposition(['statut' => StatutPropositionVehicule::REJETEE->value]);

        $this->actingAs($this->user)
            ->patch(route('propositions-vehicules.prendre-en-charge', $proposition))
            ->assertStatus(403);
    }

    // ── demanderComplement ────────────────────────────────────────────────────

    public function test_demander_complement_changes_statut_and_saves_note(): void
    {
        $proposition = $this->makeProposition();

        $this->actingAs($this->user)
            ->patch(route('propositions-vehicules.demander-complement', $proposition), [
                'decision_note' => 'Merci de fournir les documents manquants.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('propositions_vehicules', [
            'id' => $proposition->id,
            'statut' => StatutPropositionVehicule::A_COMPLETER->value,
            'decision_note' => 'Merci de fournir les documents manquants.',
        ]);
    }

    public function test_demander_complement_requires_decision_note(): void
    {
        $proposition = $this->makeProposition();

        $this->actingAs($this->user)
            ->patch(route('propositions-vehicules.demander-complement', $proposition), [])
            ->assertSessionHasErrors('decision_note');
    }

    public function test_demander_complement_returns_403_for_terminal_proposition(): void
    {
        $proposition = $this->makeProposition(['statut' => StatutPropositionVehicule::CONVERTIE->value]);

        $this->actingAs($this->user)
            ->patch(route('propositions-vehicules.demander-complement', $proposition), [
                'decision_note' => 'Note.',
            ])
            ->assertStatus(403);
    }

    // ── rejeter ───────────────────────────────────────────────────────────────

    public function test_rejeter_changes_statut_and_redirects_to_index(): void
    {
        $proposition = $this->makeProposition();

        $this->actingAs($this->user)
            ->patch(route('propositions-vehicules.rejeter', $proposition), [
                'decision_note' => 'Véhicule non conforme aux critères.',
            ])
            ->assertRedirect(route('propositions-vehicules.index'));

        $this->assertDatabaseHas('propositions_vehicules', [
            'id' => $proposition->id,
            'statut' => StatutPropositionVehicule::REJETEE->value,
            'decision_note' => 'Véhicule non conforme aux critères.',
        ]);
    }

    public function test_rejeter_requires_decision_note(): void
    {
        $proposition = $this->makeProposition();

        $this->actingAs($this->user)
            ->patch(route('propositions-vehicules.rejeter', $proposition), [])
            ->assertSessionHasErrors('decision_note');
    }

    public function test_rejeter_returns_403_for_terminal_proposition(): void
    {
        $proposition = $this->makeProposition(['statut' => StatutPropositionVehicule::CONVERTIE->value]);

        $this->actingAs($this->user)
            ->patch(route('propositions-vehicules.rejeter', $proposition), [
                'decision_note' => 'Doublon.',
            ])
            ->assertStatus(403);
    }

    // ── valider (conversion) ──────────────────────────────────────────────────

    public function test_valider_cree_proprietaire_et_vehicule_et_marque_convertie(): void
    {
        $proposition = $this->makeProposition([
            'nom_contact' => 'Ibrahima Camara',
            'telephone_contact' => '+224628111222',
            'immatriculation' => 'TV-100-GN',
            'capacite_packs' => 200,
        ]);

        $this->actingAs($this->user)
            ->post(route('propositions-vehicules.valider', $proposition))
            ->assertRedirect(route('propositions-vehicules.index'));

        $this->assertDatabaseHas('propositions_vehicules', [
            'id' => $proposition->id,
            'statut' => StatutPropositionVehicule::CONVERTIE->value,
        ]);

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'immatriculation' => 'TV-100-GN',
            'categorie' => 'externe',
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('proprietaires', [
            'organization_id' => $this->org->id,
            'telephone' => '+224628111222',
        ]);
    }

    public function test_valider_bloque_si_immatriculation_en_doublon(): void
    {
        Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'immatriculation' => 'TV-200-GN',
        ]);

        $proposition = $this->makeProposition(['immatriculation' => 'tv-200-gn']);

        $this->actingAs($this->user)
            ->post(route('propositions-vehicules.valider', $proposition))
            ->assertSessionHasErrors('conversion');

        $this->assertDatabaseHas('propositions_vehicules', [
            'id' => $proposition->id,
            'statut' => StatutPropositionVehicule::SOUMISE->value,
        ]);
    }

    public function test_valider_reutilise_proprietaire_existant_par_telephone(): void
    {
        $proprietaireExistant = Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622333444',
        ]);

        $proposition = $this->makeProposition([
            'telephone_contact' => '+224622333444',
            'immatriculation' => 'TV-300-GN',
        ]);

        $this->actingAs($this->user)
            ->post(route('propositions-vehicules.valider', $proposition))
            ->assertRedirect(route('propositions-vehicules.index'));

        $this->assertDatabaseCount('proprietaires', 1);

        $this->assertDatabaseHas('vehicules', [
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaireExistant->id,
            'immatriculation' => 'TV-300-GN',
        ]);
    }

    public function test_valider_returns_403_for_terminal_proposition(): void
    {
        $proposition = $this->makeProposition(['statut' => StatutPropositionVehicule::CONVERTIE->value]);

        $this->actingAs($this->user)
            ->post(route('propositions-vehicules.valider', $proposition))
            ->assertStatus(403);
    }

    public function test_valider_bloque_si_deja_convertie(): void
    {
        $proposition = $this->makeProposition(['statut' => StatutPropositionVehicule::CONVERTIE->value]);

        // La policy bloque avant même le service (is_terminal = true)
        $this->actingAs($this->user)
            ->post(route('propositions-vehicules.valider', $proposition))
            ->assertStatus(403);
    }
}
