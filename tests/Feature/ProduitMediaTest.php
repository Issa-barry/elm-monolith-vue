<?php

namespace Tests\Feature;

use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitType;
use App\Services\MediaService;
use App\Services\ProduitService;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class ProduitMediaTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->initOrgAndUser(['produits.read', 'produits.create', 'produits.update']);
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
    }

    private function makeProduit(): Produit
    {
        return app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit avec photos',
            'produit_type_id' => ProduitType::where('organization_id', $this->org->id)->where('code', 'service')->value('id'),
            'statut' => 'actif',
        ]);
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    public function test_ajouter_stocke_les_images_et_definit_la_premiere_comme_principale(): void
    {
        $produit = $this->makeProduit();

        $medias = app(MediaService::class)->ajouter($produit, [
            UploadedFile::fake()->image('photo1.jpg'),
            UploadedFile::fake()->image('photo2.jpg'),
        ]);

        $this->assertCount(2, $medias);
        $this->assertTrue($medias->first()->is_primary);
        $this->assertFalse($medias->last()->is_primary);
        Storage::disk('public')->assertExists($medias->first()->path);
        Storage::disk('public')->assertExists($medias->first()->thumb_path);
    }

    public function test_ajouter_respecte_la_limite_de_lorganisation(): void
    {
        Parametre::setLimitesCatalogue($this->org->id, 2, 3, 20, 100);
        $produit = $this->makeProduit();

        $this->expectException(ValidationException::class);

        app(MediaService::class)->ajouter($produit, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
            UploadedFile::fake()->image('c.jpg'),
        ]);
    }

    public function test_ajouter_cumule_avec_les_photos_existantes(): void
    {
        Parametre::setLimitesCatalogue($this->org->id, 2, 3, 20, 100);
        $produit = $this->makeProduit();
        $mediaService = app(MediaService::class);

        $mediaService->ajouter($produit, [UploadedFile::fake()->image('a.jpg')]);

        // 1 déjà présente + 2 nouvelles = 3 > limite 2
        $this->expectException(ValidationException::class);
        $mediaService->ajouter($produit, [
            UploadedFile::fake()->image('b.jpg'),
            UploadedFile::fake()->image('c.jpg'),
        ]);
    }

    // ── Image principale / ordre ─────────────────────────────────────────────────

    public function test_definir_principale_change_lunique_image_principale(): void
    {
        $produit = $this->makeProduit();
        $mediaService = app(MediaService::class);
        $medias = $mediaService->ajouter($produit, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ]);

        $mediaService->definirPrincipale($produit, $medias->last()->id);

        $this->assertDatabaseHas('produit_medias', ['id' => $medias->first()->id, 'is_primary' => false]);
        $this->assertDatabaseHas('produit_medias', ['id' => $medias->last()->id, 'is_primary' => true]);
    }

    public function test_reordonner_change_la_position(): void
    {
        $produit = $this->makeProduit();
        $mediaService = app(MediaService::class);
        $medias = $mediaService->ajouter($produit, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ]);

        $mediaService->reordonner($produit, [$medias->last()->id, $medias->first()->id]);

        $this->assertDatabaseHas('produit_medias', ['id' => $medias->last()->id, 'position' => 0]);
        $this->assertDatabaseHas('produit_medias', ['id' => $medias->first()->id, 'position' => 1]);
    }

    // ── Suppression ───────────────────────────────────────────────────────────

    public function test_supprimer_retire_le_fichier_et_promeut_la_suivante_en_principale(): void
    {
        $produit = $this->makeProduit();
        $mediaService = app(MediaService::class);
        $medias = $mediaService->ajouter($produit, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ]);

        $principale = $medias->first();
        $path = $principale->path;

        $mediaService->supprimer($principale);

        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseMissing('produit_medias', ['id' => $principale->id]);
        $this->assertDatabaseHas('produit_medias', ['id' => $medias->last()->id, 'is_primary' => true]);
    }

    // ── Intégration formulaire Produit ───────────────────────────────────────────

    public function test_store_produit_avec_images_cree_les_medias(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Produit avec galerie',
                'type' => 'service',
                'statut' => 'actif',
                'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            ]);

        $produit = Produit::where('nom', 'Produit avec galerie')->firstOrFail();
        $this->assertCount(2, $produit->medias);
    }

    public function test_store_produit_refuse_plus_dimages_que_la_limite(): void
    {
        Parametre::setLimitesCatalogue($this->org->id, 1, 3, 20, 100);

        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Trop de photos',
                'produit_type_id' => ProduitType::where('organization_id', $this->org->id)->where('code', 'service')->value('id'),
                'statut' => 'actif',
                'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            ])
            ->assertSessionHasErrors('images');

        $this->assertDatabaseMissing('produits', ['nom' => 'Trop de photos']);
    }

    public function test_update_produit_avec_image_cree_le_media(): void
    {
        // Régression : ProduitForm.vue envoyait autrefois un champ "image" (singulier) alors
        // que le backend n'écoute que "images" (pluriel, tableau) — le fichier n'atteignait
        // jamais MediaService::ajouter(), la photo semblait "disparaître" après sauvegarde.
        $produit = $this->makeProduit();

        $this->actingAs($this->user)
            ->post(route('produits.update', $produit), [
                '_method' => 'PUT',
                'nom' => $produit->nom,
                'produit_type_id' => $produit->produit_type_id,
                'statut' => $produit->statut->value,
                'images' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertRedirect(route('produits.show', $produit));

        $this->assertCount(1, $produit->fresh()->medias);
    }
}
