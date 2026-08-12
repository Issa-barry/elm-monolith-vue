<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Services\MediaService;
use App\Services\ProduitService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Association média ↔ variante (produit_variantes.media_id) : partage d'une même photo entre
 * plusieurs variantes (ex: toutes les pointures "Blanc"), résolution d'image effective avec
 * fallback, et endpoints HTTP de la galerie (MediaController) — distinct de
 * ProduitMediaTest.php qui couvre MediaService au niveau service (upload/limite/principale).
 */
class VarianteMediaTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->initOrgAndUser(['produits.read', 'produits.create', 'produits.update']);
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
    }

    private function typeId(string $code, ?Organization $org = null): string
    {
        $org ??= $this->org;
        ProduitTypeDefaultSeeder::seedPourOrganisation($org->id);

        return ProduitType::where('organization_id', $org->id)->where('code', $code)->value('id');
    }

    /** Produit à déclinaisons (2 variantes : Noir / Blanc) via le vrai chemin de création. */
    private function makeProduitDecline(?Organization $org = null): Produit
    {
        return app(ProduitService::class)->creer([
            'organization_id' => ($org ?? $this->org)->id,
            'nom' => 'T-shirt média',
            'produit_type_id' => $this->typeId('achat_vente', $org),
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 2000,
            'options' => [
                ['nom' => 'Couleur', 'valeurs' => ['Noir', 'Blanc']],
            ],
        ])->fresh(['variantes']);
    }

    // ── Endpoints HTTP de la galerie ─────────────────────────────────────────────

    public function test_store_media_via_http_ajoute_a_la_galerie(): void
    {
        $produit = $this->makeProduitDecline();

        $this->actingAs($this->user)
            ->post(route('produits.medias.store', $produit), [
                'images' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertRedirect();

        $this->assertCount(1, $produit->fresh()->medias);
    }

    public function test_store_media_returns_403_for_other_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        $produit = $this->makeProduitDecline($autreOrg);

        $this->actingAs($this->user)
            ->post(route('produits.medias.store', $produit), [
                'images' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertStatus(403);
    }

    public function test_definir_principale_via_http(): void
    {
        $produit = $this->makeProduitDecline();
        $medias = app(MediaService::class)->ajouter($produit, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ]);

        $this->actingAs($this->user)
            ->patch(route('produits.medias.principale', [$produit, $medias->last()]))
            ->assertRedirect();

        $this->assertDatabaseHas('produit_medias', ['id' => $medias->last()->id, 'is_primary' => true]);
    }

    public function test_reordonner_via_http(): void
    {
        $produit = $this->makeProduitDecline();
        $medias = app(MediaService::class)->ajouter($produit, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ]);

        $this->actingAs($this->user)
            ->patch(route('produits.medias.reordonner', $produit), [
                'ordre' => [$medias->last()->id, $medias->first()->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produit_medias', ['id' => $medias->last()->id, 'position' => 0]);
    }

    // ── Assignation à des variantes (partage, sans duplication) ──────────────────

    public function test_assigner_variantes_partage_le_meme_media_entre_plusieurs_variantes(): void
    {
        $produit = $this->makeProduitDecline();
        [$varianteNoir, $varianteBlanc] = $produit->variantes->all();
        $media = app(MediaService::class)->ajouter($produit, [UploadedFile::fake()->image('blanc.jpg')])->first();

        $this->actingAs($this->user)
            ->post(route('produits.medias.assigner-variantes', [$produit, $media]), [
                'variante_ids' => [$varianteNoir->id, $varianteBlanc->id],
            ])
            ->assertRedirect();

        // Une seule ligne produit_medias, référencée par les deux variantes — pas de duplication.
        $this->assertSame(1, $produit->medias()->count());
        $this->assertDatabaseHas('produit_variantes', ['id' => $varianteNoir->id, 'media_id' => $media->id]);
        $this->assertDatabaseHas('produit_variantes', ['id' => $varianteBlanc->id, 'media_id' => $media->id]);
    }

    public function test_assigner_variantes_refuse_les_ids_dun_autre_produit(): void
    {
        $produit = $this->makeProduitDecline();
        $autreProduit = $this->makeProduitDecline();
        $varianteAutreProduit = $autreProduit->variantes->first();
        $media = app(MediaService::class)->ajouter($produit, [UploadedFile::fake()->image('a.jpg')])->first();

        $this->actingAs($this->user)
            ->post(route('produits.medias.assigner-variantes', [$produit, $media]), [
                'variante_ids' => [$varianteAutreProduit->id],
            ])
            ->assertSessionHasErrors('variante_ids.0');

        $this->assertDatabaseHas('produit_variantes', ['id' => $varianteAutreProduit->id, 'media_id' => null]);
    }

    public function test_assigner_variantes_returns_403_for_other_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        $produit = $this->makeProduitDecline($autreOrg);
        $media = app(MediaService::class)->ajouter($produit, [UploadedFile::fake()->image('a.jpg')])->first();

        $this->actingAs($this->user)
            ->post(route('produits.medias.assigner-variantes', [$produit, $media]), [
                'variante_ids' => [$produit->variantes->first()->id],
            ])
            ->assertStatus(403);
    }

    // ── Résolution de l'image effective (fallback) ───────────────────────────────

    public function test_effective_image_url_variante_propre(): void
    {
        $produit = $this->makeProduitDecline();
        $variante = $produit->variantes->first();
        $media = app(MediaService::class)->ajouter($produit, [UploadedFile::fake()->image('a.jpg')])->first();
        $variante->update(['media_id' => $media->id]);

        $this->assertSame($media->url, $variante->fresh()->effective_image_url);
    }

    public function test_effective_image_url_retombe_sur_image_principale_produit(): void
    {
        $produit = $this->makeProduitDecline();
        $variante = $produit->variantes->first();
        $mediaProduit = app(MediaService::class)->ajouter($produit, [UploadedFile::fake()->image('principale.jpg')])->first();

        // La variante n'a pas de média propre — doit retomber sur l'image principale du produit.
        $this->assertSame($mediaProduit->url, $variante->fresh()->effective_image_url);
    }

    public function test_effective_image_url_null_si_aucune_image(): void
    {
        $produit = $this->makeProduitDecline();
        $variante = $produit->variantes->first();

        $this->assertNull($variante->fresh()->effective_image_url);
    }

    public function test_supprimer_media_retombe_les_variantes_sur_image_principale(): void
    {
        $produit = $this->makeProduitDecline();
        [$varianteNoir, $varianteBlanc] = $produit->variantes->all();
        $mediaService = app(MediaService::class);

        $mediaPrincipal = $mediaService->ajouter($produit, [UploadedFile::fake()->image('principale.jpg')])->first();
        $mediaNoir = $mediaService->ajouter($produit, [UploadedFile::fake()->image('noir.jpg')])->first();
        $mediaService->assignerAuxVariantes($mediaNoir, [$varianteNoir->id]);

        $this->actingAs($this->user)
            ->delete(route('produits.medias.destroy', [$produit, $mediaNoir]))
            ->assertRedirect();

        // nullOnDelete : la variante ne référence plus le média supprimé, et l'accessor
        // retombe automatiquement sur l'image principale du produit (jamais de lien cassé).
        $this->assertDatabaseHas('produit_variantes', ['id' => $varianteNoir->id, 'media_id' => null]);
        $this->assertSame($mediaPrincipal->url, $varianteNoir->fresh()->effective_image_url);
        $this->assertSame($mediaPrincipal->url, $varianteBlanc->fresh()->effective_image_url);
    }

    public function test_destroy_media_returns_403_for_other_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        $produit = $this->makeProduitDecline($autreOrg);
        $media = app(MediaService::class)->ajouter($produit, [UploadedFile::fake()->image('a.jpg')])->first();

        $this->actingAs($this->user)
            ->delete(route('produits.medias.destroy', [$produit, $media]))
            ->assertStatus(403);
    }

    // ── Édition individuelle (updateVariante avec media_id) ──────────────────────

    public function test_update_variante_peut_definir_le_media_id(): void
    {
        $produit = $this->makeProduitDecline();
        $variante = $produit->variantes->first();
        $media = app(MediaService::class)->ajouter($produit, [UploadedFile::fake()->image('a.jpg')])->first();

        $this->actingAs($this->user)
            ->put(route('produits.variantes.update', [$produit, $variante]), [
                'prix_vente' => (int) $variante->prix_vente,
                'prix_achat' => (int) $variante->prix_achat,
                'media_id' => $media->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produit_variantes', ['id' => $variante->id, 'media_id' => $media->id]);
    }

    public function test_update_variante_refuse_un_media_dun_autre_produit(): void
    {
        $produit = $this->makeProduitDecline();
        $autreProduit = $this->makeProduitDecline();
        $variante = $produit->variantes->first();
        $mediaAutreProduit = app(MediaService::class)->ajouter($autreProduit, [UploadedFile::fake()->image('a.jpg')])->first();

        $this->actingAs($this->user)
            ->put(route('produits.variantes.update', [$produit, $variante]), [
                'prix_vente' => (int) $variante->prix_vente,
                'prix_achat' => (int) $variante->prix_achat,
                'media_id' => $mediaAutreProduit->id,
            ])
            ->assertSessionHasErrors('media_id');
    }
}
