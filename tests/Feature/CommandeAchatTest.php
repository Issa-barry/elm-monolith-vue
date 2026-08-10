<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeAchat;
use App\Features\ModuleFeature;
use App\Models\CommandeAchat;
use App\Models\Fournisseur;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommandeAchatTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['achats.read', 'achats.create', 'achats.update', 'achats.delete']);
        // Le module achats est désactivé par défaut — l'activer pour les tests
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
    }

    private function makeContext(Organization $org): array
    {
        $produit = $this->makeProduitAvecVariante(
            $org,
            ['nom' => 'Produit achat test', 'qte_stock' => 0],
            ['prix_achat' => 1000],
        );

        $fournisseur = Fournisseur::create([
            'organization_id' => $org->id,
            'nom' => 'FOURNISSEUR TEST',
            'is_active' => true,
        ]);

        return compact('produit', 'fournisseur');
    }

    private function makeCommande(Organization $org, array $overrides = []): CommandeAchat
    {
        return CommandeAchat::create(array_merge([
            'organization_id' => $org->id,
            'total_commande' => 5000,
            'statut' => StatutCommandeAchat::EN_COURS,
        ], $overrides));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('achats.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('achats.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('achats.index'))
            ->assertStatus(403);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('achats.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_commande_achat_and_redirects(): void
    {
        ['produit' => $produit, 'fournisseur' => $fournisseur] = $this->makeContext($this->org);

        $response = $this->actingAs($this->user)
            ->post(route('achats.store'), [
                'fournisseur_id' => $fournisseur->id,
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
            'organization_id' => $this->org->id,
            'fournisseur_id' => $fournisseur->id,
        ]);
    }

    public function test_store_fails_with_empty_lignes(): void
    {
        $this->actingAs($this->user)
            ->post(route('achats.store'), ['lignes' => []])
            ->assertSessionHasErrors('lignes');
    }

    public function test_store_fails_without_lignes(): void
    {
        $this->actingAs($this->user)
            ->post(route('achats.store'), [])
            ->assertSessionHasErrors('lignes');
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_200_for_authorized_user(): void
    {
        $commande = $this->makeCommande($this->org);

        $this->actingAs($this->user)
            ->get(route('achats.show', $commande))
            ->assertStatus(200);
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $commande = $this->makeCommande($otherOrg);

        $this->actingAs($this->user)
            ->get(route('achats.show', $commande))
            ->assertStatus(403);
    }

    // ── receptionner ──────────────────────────────────────────────────────────

    public function test_receptionner_updates_statut_to_receptionnee(): void
    {
        ['produit' => $produit] = $this->makeContext($this->org);
        $commande = $this->makeCommande($this->org);
        $ligne = $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'qte' => 3,
            'prix_achat_snapshot' => 1000,
            'total_ligne' => 3000,
        ]);

        $this->actingAs($this->user)
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
        $commande = $this->makeCommande($this->org, ['statut' => StatutCommandeAchat::ANNULEE]);

        $this->actingAs($this->user)
            ->patch(route('achats.receptionner', $commande), ['lignes' => []])
            ->assertStatus(422);
    }

    public function test_receptionner_returns_422_if_already_receptionnee(): void
    {
        $commande = $this->makeCommande($this->org, ['statut' => StatutCommandeAchat::RECEPTIONNEE]);

        $this->actingAs($this->user)
            ->patch(route('achats.receptionner', $commande), ['lignes' => []])
            ->assertStatus(422);
    }

    // ── annuler ───────────────────────────────────────────────────────────────

    public function test_annuler_sets_statut_annulee(): void
    {
        $commande = $this->makeCommande($this->org);

        $this->actingAs($this->user)
            ->patch(route('achats.annuler', $commande), [
                'motif_annulation' => 'Annulation test achat',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeAchat::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annuler_fails_without_motif(): void
    {
        $commande = $this->makeCommande($this->org);

        $this->actingAs($this->user)
            ->patch(route('achats.annuler', $commande), [])
            ->assertSessionHasErrors('motif_annulation');
    }

    public function test_annuler_returns_422_if_already_annulee(): void
    {
        $commande = $this->makeCommande($this->org, ['statut' => StatutCommandeAchat::ANNULEE]);

        $this->actingAs($this->user)
            ->patch(route('achats.annuler', $commande), [
                'motif_annulation' => 'Tentative double',
            ])
            ->assertStatus(422);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_annulee_commande_and_redirects(): void
    {
        $commande = $this->makeCommande($this->org, ['statut' => StatutCommandeAchat::ANNULEE]);

        $this->actingAs($this->user)
            ->delete(route('achats.destroy', $commande))
            ->assertRedirect(route('achats.index'));

        $this->assertSoftDeleted('commandes_achats', ['id' => $commande->id]);
    }

    public function test_destroy_returns_403_for_non_annulee_commande(): void
    {
        $commande = $this->makeCommande($this->org);

        $this->actingAs($this->user)
            ->delete(route('achats.destroy', $commande))
            ->assertStatus(403);
    }

    // ── pdf ───────────────────────────────────────────────────────────────────

    public function test_pdf_returns_200_with_pdf_content_type(): void
    {
        $commande = $this->makeCommande($this->org);

        $response = $this->actingAs($this->user)
            ->get(route('achats.pdf', $commande));

        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_pdf_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $commande = $this->makeCommande($otherOrg);

        $this->actingAs($this->user)
            ->get(route('achats.pdf', $commande))
            ->assertStatus(403);
    }
}
