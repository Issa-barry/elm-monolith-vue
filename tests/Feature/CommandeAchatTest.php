<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeAchat;
use App\Models\CommandeAchat;
use App\Models\Organization;
use App\Models\Prestataire;
use App\Models\Produit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

class CommandeAchatTest extends TestCase
{
    use HasAdminSetup, RefreshDatabase;

    private function user(): User
    {
        return $this->makeAdminUser();
    }

    private function userWithPermissions(Organization $org): User
    {
        return $this->makeUserWithPermissions($org, ['achats.read', 'achats.create', 'achats.update', 'achats.delete']);
    }

    private function makeContext(Organization $org): array
    {
        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Produit achat test',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 1000,
            'qte_stock' => 0,
        ]);

        $prestataire = Prestataire::create([
            'organization_id' => $org->id,
            'nom' => 'FOURNISSEUR TEST',
            'type' => 'fournisseur',
            'is_active' => true,
        ]);

        return compact('produit', 'prestataire');
    }

    private function makeCommande(Organization $org, array $overrides = []): CommandeAchat
    {
        $commande = CommandeAchat::create(array_merge([
            'organization_id' => $org->id,
            'total_commande' => 5000,
            'statut' => StatutCommandeAchat::EN_COURS,
        ], $overrides));

        return $commande;
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('achats.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('achats.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get(route('achats.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->get(route('achats.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_commande_achat_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        ['produit' => $produit, 'prestataire' => $prestataire] = $this->makeContext($org);

        $response = $this->actingAs($user)
            ->post(route('achats.store'), [
                'prestataire_id' => $prestataire->id,
                'lignes' => [
                    [
                        'produit_id' => $produit->id,
                        'qte' => 5,
                        'prix_achat' => 1000,
                    ],
                ],
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('commandes_achats', [
            'organization_id' => $org->id,
            'prestataire_id' => $prestataire->id,
        ]);
    }

    public function test_store_fails_with_empty_lignes(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('achats.store'), ['lignes' => []])
            ->assertSessionHasErrors('lignes');
    }

    public function test_store_fails_without_lignes(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);

        $this->actingAs($user)
            ->post(route('achats.store'), [])
            ->assertSessionHasErrors('lignes');
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_200_for_authorized_user(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = $this->makeCommande($org);

        $this->actingAs($user)
            ->get(route('achats.show', $commande))
            ->assertStatus(200);
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $commande = $this->makeCommande($otherOrg);

        $this->actingAs($user)
            ->get(route('achats.show', $commande))
            ->assertStatus(403);
    }

    // ── receptionner ──────────────────────────────────────────────────────────

    public function test_receptionner_updates_statut_to_receptionnee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        ['produit' => $produit] = $this->makeContext($org);
        $commande = $this->makeCommande($org);
        $ligne = $commande->lignes()->create([
            'produit_id' => $produit->id,
            'qte' => 3,
            'prix_achat_snapshot' => 1000,
            'total_ligne' => 3000,
        ]);

        $this->actingAs($user)
            ->patch(route('achats.receptionner', $commande), [
                'lignes' => [
                    ['id' => $ligne->id, 'qte_recue' => 3],
                ],
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeAchat::RECEPTIONNEE, $commande->fresh()->statut);
    }

    public function test_receptionner_returns_422_if_annulee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = $this->makeCommande($org, ['statut' => StatutCommandeAchat::ANNULEE]);

        $this->actingAs($user)
            ->patch(route('achats.receptionner', $commande), ['lignes' => []])
            ->assertStatus(422);
    }

    public function test_receptionner_returns_422_if_already_receptionnee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = $this->makeCommande($org, ['statut' => StatutCommandeAchat::RECEPTIONNEE]);

        $this->actingAs($user)
            ->patch(route('achats.receptionner', $commande), ['lignes' => []])
            ->assertStatus(422);
    }

    // ── annuler ───────────────────────────────────────────────────────────────

    public function test_annuler_sets_statut_annulee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = $this->makeCommande($org);

        $this->actingAs($user)
            ->patch(route('achats.annuler', $commande), [
                'motif_annulation' => 'Annulation test achat',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeAchat::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annuler_fails_without_motif(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = $this->makeCommande($org);

        $this->actingAs($user)
            ->patch(route('achats.annuler', $commande), [])
            ->assertSessionHasErrors('motif_annulation');
    }

    public function test_annuler_returns_422_if_already_annulee(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = $this->makeCommande($org, ['statut' => StatutCommandeAchat::ANNULEE]);

        $this->actingAs($user)
            ->patch(route('achats.annuler', $commande), [
                'motif_annulation' => 'Tentative double',
            ])
            ->assertStatus(422);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_annulee_commande_and_redirects(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = $this->makeCommande($org, ['statut' => StatutCommandeAchat::ANNULEE]);

        $this->actingAs($user)
            ->delete(route('achats.destroy', $commande))
            ->assertRedirect(route('achats.index'));

        $this->assertSoftDeleted('commandes_achats', ['id' => $commande->id]);
    }

    public function test_destroy_returns_403_for_non_annulee_commande(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = $this->makeCommande($org);

        $this->actingAs($user)
            ->delete(route('achats.destroy', $commande))
            ->assertStatus(403);
    }

    // ── pdf ───────────────────────────────────────────────────────────────────

    public function test_pdf_returns_200_with_pdf_content_type(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $commande = $this->makeCommande($org);

        $response = $this->actingAs($user)
            ->get(route('achats.pdf', $commande));

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_pdf_returns_403_for_other_organization(): void
    {
        $org = Organization::factory()->create();
        $user = $this->userWithPermissions($org);
        $otherOrg = Organization::factory()->create();
        $commande = $this->makeCommande($otherOrg);

        $this->actingAs($user)
            ->get(route('achats.pdf', $commande))
            ->assertStatus(403);
    }
}
