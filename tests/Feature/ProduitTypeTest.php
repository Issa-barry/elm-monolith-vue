<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Services\ProduitService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * CRUD des types de produit — remplace l'ancien enum figé App\Enums\ProduitType. Couvre
 * en particulier la protection structurelle (Q3 de la décision produit) : un type déjà
 * utilisé par au moins un produit ne peut plus voir sa structure modifiée ni être supprimé.
 */
class ProduitTypeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['type-produits.read', 'type-produits.create', 'type-produits.update', 'type-produits.delete', 'produits.create']);
    }

    private function produitTypesRoute(string $suffix = ''): string
    {
        return '/backoffice/produits/types'.$suffix;
    }

    // ── provisioning par défaut ──────────────────────────────────────────────────

    public function test_provisioning_cree_les_5_types_historiques(): void
    {
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);

        $codes = ProduitType::where('organization_id', $this->org->id)->pluck('code')->sort()->values()->all();
        $this->assertSame(['achat_vente', 'fabricable', 'materiel', 'matiere_production', 'service'], $codes);
    }

    public function test_provisioning_reproduit_les_capacites_historiques(): void
    {
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);

        $service = ProduitType::where('organization_id', $this->org->id)->where('code', 'service')->firstOrFail();
        $this->assertFalse($service->gere_stock);
        $this->assertSame([], $service->requiredPrices());

        $fabricable = ProduitType::where('organization_id', $this->org->id)->where('code', 'fabricable')->firstOrFail();
        $this->assertTrue($fabricable->gere_stock);
        // prix_usine_tricycle dérivé de prix_usine_requis (cf. ProduitType::requiredPrices()) :
        // les deux tarifs usine sont deux décisions distinctes, jamais l'une déduite de l'autre.
        $this->assertSame(['prix_usine', 'prix_usine_tricycle', 'prix_vente'], $fabricable->requiredPrices());
        $this->assertSame('prix_usine', $fabricable->champPrixReference());
    }

    public function test_provisioning_est_idempotent(): void
    {
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);

        $this->assertSame(5, ProduitType::where('organization_id', $this->org->id)->count());
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200(): void
    {
        $this->actingAs($this->user)
            ->get($this->produitTypesRoute())
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_type(): void
    {
        $this->actingAs($this->user)
            ->post($this->produitTypesRoute(), [
                'nom' => 'Consommable',
                'gere_stock' => true,
                'vendable' => false,
                'achetable' => true,
                'prix_achat_requis' => true,
                'prix_usine_requis' => false,
                'prix_vente_requis' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produit_types', [
            'organization_id' => $this->org->id,
            'nom' => 'Consommable',
            'gere_stock' => true,
        ]);
    }

    public function test_store_genere_un_code_slug_stable(): void
    {
        $this->actingAs($this->user)
            ->post($this->produitTypesRoute(), [
                'nom' => 'Pièce détachée',
                'gere_stock' => true,
                'vendable' => false,
                'achetable' => true,
                'prix_achat_requis' => true,
                'prix_usine_requis' => false,
                'prix_vente_requis' => false,
            ]);

        $type = ProduitType::where('organization_id', $this->org->id)->where('nom', 'Pièce détachée')->firstOrFail();
        $this->assertNotEmpty($type->code);
    }

    public function test_store_refuse_reference_prix_qui_nest_pas_dans_les_prix_requis(): void
    {
        $this->actingAs($this->user)
            ->post($this->produitTypesRoute(), [
                'nom' => 'Type incohérent',
                'gere_stock' => true,
                'vendable' => true,
                'achetable' => false,
                'prix_achat_requis' => false,
                'prix_usine_requis' => false,
                'prix_vente_requis' => true,
                'champ_prix_reference' => 'prix_achat',
            ])
            ->assertSessionHasErrors('champ_prix_reference');
    }

    // ── protection structurelle (type déjà utilisé) ─────────────────────────────

    private function makeTypeUtilise(): ProduitType
    {
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
        $type = ProduitType::where('organization_id', $this->org->id)->where('code', 'materiel')->firstOrFail();

        app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit qui utilise ce type',
            'produit_type_id' => $type->id,
            'statut' => 'actif',
            'prix_achat' => 500,
        ]);

        return $type;
    }

    public function test_update_refuse_de_changer_gere_stock_si_type_utilise(): void
    {
        $type = $this->makeTypeUtilise();

        $this->actingAs($this->user)
            ->put($this->produitTypesRoute("/{$type->id}"), [
                'nom' => $type->nom,
                'gere_stock' => false,
            ])
            ->assertSessionHasErrors('structure');

        $this->assertTrue($type->fresh()->gere_stock);
    }

    public function test_update_autorise_le_nom_et_le_statut_meme_si_type_utilise(): void
    {
        $type = $this->makeTypeUtilise();

        $this->actingAs($this->user)
            ->put($this->produitTypesRoute("/{$type->id}"), [
                'nom' => 'Matériel renommé',
                'statut' => 'inactif',
                'gere_stock' => $type->gere_stock,
                'vendable' => $type->vendable,
                'achetable' => $type->achetable,
                'prix_achat_requis' => $type->prix_achat_requis,
                'prix_usine_requis' => $type->prix_usine_requis,
                'prix_vente_requis' => $type->prix_vente_requis,
            ])
            ->assertSessionDoesntHaveErrors();

        $type->refresh();
        $this->assertSame('Matériel renommé', $type->nom);
        $this->assertSame('inactif', $type->statut->value);
    }

    public function test_update_autorise_la_structure_si_type_non_utilise(): void
    {
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
        $type = ProduitType::where('organization_id', $this->org->id)->where('code', 'materiel')->firstOrFail();

        $this->actingAs($this->user)
            ->put($this->produitTypesRoute("/{$type->id}"), [
                'nom' => $type->nom,
                'gere_stock' => false,
                'vendable' => $type->vendable,
                'achetable' => $type->achetable,
                'prix_achat_requis' => $type->prix_achat_requis,
                'prix_usine_requis' => $type->prix_usine_requis,
                'prix_vente_requis' => $type->prix_vente_requis,
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertFalse($type->fresh()->gere_stock);
    }

    public function test_destroy_refuse_si_type_utilise(): void
    {
        $type = $this->makeTypeUtilise();

        $this->actingAs($this->user)
            ->delete($this->produitTypesRoute("/{$type->id}"))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('produit_types', ['id' => $type->id, 'deleted_at' => null]);
    }

    public function test_destroy_autorise_si_type_non_utilise(): void
    {
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
        $type = ProduitType::where('organization_id', $this->org->id)->where('code', 'service')->firstOrFail();

        $this->actingAs($this->user)
            ->delete($this->produitTypesRoute("/{$type->id}"))
            ->assertRedirect();

        $this->assertSoftDeleted('produit_types', ['id' => $type->id]);
    }

    public function test_toggle_desactive_puis_reactive_le_type(): void
    {
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
        $type = ProduitType::where('organization_id', $this->org->id)->where('code', 'materiel')->firstOrFail();

        $this->actingAs($this->user)->patch($this->produitTypesRoute("/{$type->id}/toggle"));
        $this->assertSame('inactif', $type->fresh()->statut->value);

        $this->actingAs($this->user)->patch($this->produitTypesRoute("/{$type->id}/toggle"));
        $this->assertSame('actif', $type->fresh()->statut->value);
    }

    public function test_type_desactive_nest_plus_proposable_a_la_creation_dun_produit(): void
    {
        $type = $this->makeTypeUtilise();
        $type->update(['statut' => 'inactif']);

        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Nouveau produit',
                'produit_type_id' => $type->id,
                'statut' => 'actif',
                'prix_achat' => 500,
            ])
            ->assertSessionHasErrors('produit_type_id');
    }

    // ── isolation multi-tenant ───────────────────────────────────────────────────

    public function test_ne_peut_pas_modifier_un_type_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($autreOrg->id);
        $typeAilleurs = ProduitType::where('organization_id', $autreOrg->id)->where('code', 'materiel')->firstOrFail();

        $this->actingAs($this->user)
            ->put($this->produitTypesRoute("/{$typeAilleurs->id}"), ['nom' => 'Piraté'])
            ->assertStatus(403);
    }
}
