<?php

namespace Tests\Feature;

use App\Models\EquipeLivraison;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class EquipeLivraisonTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([
            'equipes-livraison.read',
            'equipes-livraison.create',
            'equipes-livraison.update',
            'equipes-livraison.delete',
        ]);
    }

    private function validPayload(string $proprietaireId, array $overrides = []): array
    {
        return array_merge([
            'nom' => 'Équipe Test',
            'is_active' => true,
            'proprietaire_id' => $proprietaireId,
            'taux_commission_proprietaire' => 70,
            'membres' => [
                [
                    'livreur_id' => null,
                    'nom' => 'Diallo',
                    'prenom' => 'Mamadou',
                    'telephone' => '+224620000001',
                    'role' => 'chauffeur',
                    'taux_commission' => 30,
                    'ordre' => 0,
                ],
            ],
        ], $overrides);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('equipes-livraison.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('equipes-livraison.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('equipes-livraison.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('equipes-livraison.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_equipe_avec_proprietaire_meme_org(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirect(route('equipes-livraison.index'));

        $this->assertDatabaseHas('equipes_livraison', [
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'nom' => 'Équipe Test',
            'taux_commission_proprietaire' => 70,
        ]);
    }

    public function test_store_persiste_telephone_normalise_e164(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirect(route('equipes-livraison.index'));

        $this->assertDatabaseHas('livreurs', [
            'telephone' => '+224620000001',
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_store_echoue_si_telephone_contient_lettres(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'membres' => [[
                    'livreur_id' => null,
                    'nom' => 'Diallo', 'prenom' => 'Mamadou',
                    'telephone' => '+224abc123456', 'role' => 'chauffeur',
                    'taux_commission' => 30, 'ordre' => 0,
                ]],
            ]))
            ->assertSessionHasErrors('membres.0.telephone');
    }

    public function test_store_echoue_si_telephone_longueur_incorrecte(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        // 8 chiffres locaux au lieu de 9
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'membres' => [[
                    'livreur_id' => null,
                    'nom' => 'Diallo', 'prenom' => 'Mamadou',
                    'telephone' => '+22462012345', 'role' => 'chauffeur',
                    'taux_commission' => 30, 'ordre' => 0,
                ]],
            ]))
            ->assertSessionHasErrors('membres.0.telephone');
    }

    public function test_store_echoue_si_proprietaire_id_absent(): void
    {
        $vehicule = $this->makeVehicule();
        $payload = $this->validPayload(0, ['vehicule_id' => $vehicule->id]);
        unset($payload['proprietaire_id']);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $payload)
            ->assertSessionHasErrors('proprietaire_id');
    }

    public function test_store_autorise_vehicule_interne_sans_proprietaire(): void
    {
        $vehiculeInterne = $this->makeVehiculeInterne();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload(0, [
                'vehicule_id' => $vehiculeInterne->id,
                'proprietaire_id' => null,
                'nom' => 'Ã‰quipe Interne',
            ]))
            ->assertRedirect(route('equipes-livraison.index'));

        $this->assertDatabaseHas('equipes_livraison', [
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehiculeInterne->id,
            'proprietaire_id' => null,
            'nom' => 'Ã‰quipe Interne',
        ]);
    }

    public function test_store_echoue_si_proprietaire_autre_org(): void
    {
        $autreOrg = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $autreOrg->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id))
            ->assertSessionHasErrors('proprietaire_id');
    }

    public function test_store_persiste_taux_commission_proprietaire(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule->id,
                'taux_commission_proprietaire' => 75.50,
                'membres' => [[
                    'livreur_id' => null,
                    'nom' => 'Diallo', 'prenom' => 'Mamadou',
                    'telephone' => '+224620000001', 'role' => 'chauffeur',
                    'taux_commission' => 24.50, 'ordre' => 0,
                ]],
            ]))
            ->assertRedirect(route('equipes-livraison.index'));

        $equipe = EquipeLivraison::where('organization_id', $this->org->id)
            ->where('proprietaire_id', $proprietaire->id)
            ->first();

        $this->assertNotNull($equipe);
        $this->assertEquals(75.50, (float) $equipe->taux_commission_proprietaire);
    }

    public function test_store_echoue_si_taux_superieur_100(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'taux_commission_proprietaire' => 101,
            ]))
            ->assertSessionHasErrors('taux_commission_proprietaire');
    }

    public function test_store_echoue_si_taux_negatif(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'taux_commission_proprietaire' => -1,
            ]))
            ->assertSessionHasErrors('taux_commission_proprietaire');
    }

    public function test_store_echoue_si_total_taux_different_de_100(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        // propriétaire 60 + livreur 30 = 90 % → doit échouer
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule->id,
                'taux_commission_proprietaire' => 60,
                'membres' => [[
                    'livreur_id' => null,
                    'nom' => 'Diallo', 'prenom' => 'Mamadou',
                    'telephone' => '+224620000001', 'role' => 'chauffeur',
                    'taux_commission' => 30, 'ordre' => 0,
                ]],
            ]))
            ->assertStatus(422);
    }

    public function test_store_echoue_sans_membre(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'membres' => [],
            ]))
            ->assertSessionHasErrors('membres');
    }

    public function test_store_fails_si_nom_deja_utilise_dans_meme_org(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirect(route('equipes-livraison.index'));

        // Même nom, membre différent pour éviter conflit livreur
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'membres' => [[
                    'livreur_id' => null,
                    'nom' => 'Barry', 'prenom' => 'Ibrahima',
                    'telephone' => '+224620000002', 'role' => 'chauffeur',
                    'taux_commission' => 30, 'ordre' => 0,
                ]],
            ]))
            ->assertSessionHasErrors('nom');
    }

    public function test_store_autorise_meme_nom_apres_suppression_equipe(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule1 = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule1->id]))
            ->assertRedirect(route('equipes-livraison.index'));

        $equipe = EquipeLivraison::where('organization_id', $this->org->id)->first();

        $this->actingAs($this->user)
            ->delete(route('equipes-livraison.destroy', $equipe))
            ->assertRedirect(route('equipes-livraison.index'));

        // Le même nom doit être disponible après suppression (soft-delete)
        // vehicule1 est libéré après le soft-delete de l'équipe
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule1->id,
                'membres' => [[
                    'livreur_id' => null,
                    'nom' => 'Diallo', 'prenom' => 'Mamadou',
                    'telephone' => '+224620000002', 'role' => 'chauffeur',
                    'taux_commission' => 30, 'ordre' => 0,
                ]],
            ]))
            ->assertRedirect(route('equipes-livraison.index'));
    }

    public function test_store_fails_si_livreur_deja_dans_autre_equipe(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirect(route('equipes-livraison.index'));

        // Même livreur (+224620000001) dans une autre équipe
        $vehicule2 = $this->makeVehicule();
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule2->id,
                'nom' => 'Équipe Deux',
                'membres' => [[
                    'livreur_id' => null,
                    'nom' => 'Diallo', 'prenom' => 'Mamadou',
                    'telephone' => '+224620000001',
                    'role' => 'chauffeur',
                    'taux_commission' => 30, 'ordre' => 0,
                ]],
            ]))
            ->assertSessionHasErrors('membres.0.telephone');
    }

    public function test_update_autorise_membres_deja_dans_meme_equipe(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirect(route('equipes-livraison.index'));

        $equipe = EquipeLivraison::where('organization_id', $this->org->id)->first();

        // Mettre à jour en conservant le même membre → doit réussir
        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirect(route('equipes-livraison.edit', $equipe));
    }

    public function test_update_fails_si_livreur_deja_dans_autre_equipe(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule1 = $this->makeVehicule();
        $vehicule2 = $this->makeVehicule();

        // Equipe 1 avec +224620000001
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule1->id]))
            ->assertRedirect(route('equipes-livraison.index'));

        // Equipe 2 avec +224620000002
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule2->id,
                'nom' => 'Équipe Deux',
                'membres' => [[
                    'livreur_id' => null,
                    'nom' => 'Barry', 'prenom' => 'Ibrahima',
                    'telephone' => '+224620000002', 'role' => 'chauffeur',
                    'taux_commission' => 30, 'ordre' => 0,
                ]],
            ]))
            ->assertRedirect(route('equipes-livraison.index'));

        $equipe2 = EquipeLivraison::where('organization_id', $this->org->id)
            ->where('nom', 'Équipe Deux')->first();

        // Tenter d'affecter le livreur de l'équipe 1 à l'équipe 2
        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe2), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule2->id,
                'nom' => 'Équipe Deux',
                'membres' => [[
                    'livreur_id' => null,
                    'nom' => 'Diallo', 'prenom' => 'Mamadou',
                    'telephone' => '+224620000001',
                    'role' => 'chauffeur',
                    'taux_commission' => 30, 'ordre' => 0,
                ]],
            ]))
            ->assertSessionHasErrors('membres.0.telephone');
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = $this->makeEquipe($proprietaire->id);

        $this->actingAs($this->user)
            ->get(route('equipes-livraison.edit', $equipe))
            ->assertStatus(200);
    }

    public function test_edit_returns_403_for_other_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $autreOrg->id]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $autreOrg->id,
            'proprietaire_id' => $proprietaire->id,
            'nom' => 'Équipe Autre Org',
            'is_active' => true,
            'taux_commission_proprietaire' => 60,
        ]);

        $this->actingAs($this->user)
            ->get(route('equipes-livraison.edit', $equipe))
            ->assertStatus(403);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifie_equipe_et_redirige(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = $this->makeEquipe($proprietaire->id);
        $vehicule = $this->makeVehicule();

        $nouveauProprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe), $this->validPayload($nouveauProprietaire->id, [
                'vehicule_id' => $vehicule->id,
                'nom' => 'Équipe Modifiée',
                'taux_commission_proprietaire' => 55,
                'membres' => [[
                    'livreur_id' => null,
                    'nom' => 'Diallo', 'prenom' => 'Mamadou',
                    'telephone' => '+224620000001', 'role' => 'chauffeur',
                    'taux_commission' => 45, 'ordre' => 0,
                ]],
            ]))
            ->assertRedirect(route('equipes-livraison.edit', $equipe));

        $this->assertDatabaseHas('equipes_livraison', [
            'id' => $equipe->id,
            'proprietaire_id' => $nouveauProprietaire->id,
            'nom' => 'Équipe Modifiée',
            'taux_commission_proprietaire' => 55,
        ]);
    }

    public function test_update_echoue_si_proprietaire_autre_org(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = $this->makeEquipe($proprietaire->id);

        $autreOrg = Organization::factory()->create();
        $autreProprietaire = Proprietaire::factory()->create(['organization_id' => $autreOrg->id]);

        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe), $this->validPayload($autreProprietaire->id))
            ->assertSessionHasErrors('proprietaire_id');
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_supprime_equipe_et_redirige(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = $this->makeEquipe($proprietaire->id);

        $this->actingAs($this->user)
            ->delete(route('equipes-livraison.destroy', $equipe))
            ->assertRedirect(route('equipes-livraison.index'));

        $this->assertSoftDeleted('equipes_livraison', ['id' => $equipe->id]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeVehicule(): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'externe',
        ]);
    }

    private function makeVehiculeInterne(): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => null,
            'categorie' => 'interne',
        ]);
    }

    private function makeEquipe(string $proprietaireId): EquipeLivraison
    {
        return EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaireId,
            'vehicule_id' => null,
            'nom' => 'Équipe Fixture',
            'is_active' => true,
            'taux_commission_proprietaire' => 60,
        ]);
    }
}
