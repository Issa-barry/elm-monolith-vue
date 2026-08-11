<?php

namespace Tests\Feature;

use App\Models\DroitAjustementStock;
use App\Models\Fournisseur;
use App\Models\MouvementStock;
use App\Models\OptionCatalogue;
use App\Models\Organization;
use App\Models\Prestataire;
use App\Models\Produit;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use App\Services\ProduitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class ProduitTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['produits.read', 'produits.create', 'produits.update', 'produits.delete']);
    }

    private function defaultSite(): Site
    {
        return $this->user->sites()->wherePivot('is_default', true)->first();
    }

    /** Crée un utilisateur NON admin, affecté à un site de l'organisation courante. */
    private function makeNonAdminUserOnSite(Site $site): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'produits.read', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'produits.update', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo(['produits.read', 'produits.update']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    /**
     * Produit + variante par défaut. $qteStock alimente directement le cache
     * produits.qte_stock SANS créer de ligne variante_stocks (reproduit le scénario
     * "compteur global pas encore ventilé par site", utilisé par les tests de migration
     * au premier ajustement).
     */
    private function makeProduit(Organization $org, int $qteStock = 50): Produit
    {
        $produit = app(ProduitService::class)->creer([
            'organization_id' => $org->id,
            'nom' => 'Produit test',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 500,
            'is_alerte' => false,
        ]);

        if ($qteStock !== 0) {
            $produit->updateQuietly(['qte_stock' => $qteStock]);
        }

        return $produit->fresh();
    }

    private function varianteId(Produit $produit): string
    {
        return $produit->variantePrincipale()->first()->id;
    }

    private function makeFournisseur(Organization $org, array $overrides = []): Fournisseur
    {
        return Fournisseur::create(array_merge([
            'organization_id' => $org->id,
            'raison_sociale' => 'SOGUIDEP',
            'phone' => '+224620000009',
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
            'is_active' => true,
        ], $overrides));
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('produits.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('produits.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('produits.index'))
            ->assertStatus(403);
    }

    public function test_index_filtre_par_type(): void
    {
        Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Produit materiel',
            'type' => 'materiel',
            'statut' => 'actif',
            'is_alerte' => false,
        ]);
        Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Produit service',
            'type' => 'service',
            'statut' => 'actif',
            'is_alerte' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('produits.index', ['type' => 'materiel']));

        $response->assertStatus(200);
        $produits = $response->original->getData()['page']['props']['produits'];
        $this->assertCount(1, $produits);
        $this->assertSame('Produit materiel', $produits[0]['nom']);
    }

    public function test_index_filtre_par_statut(): void
    {
        Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Actif',
            'type' => 'materiel',
            'statut' => 'actif',
            'is_alerte' => false,
        ]);
        Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Archivé',
            'type' => 'materiel',
            'statut' => 'archive',
            'is_alerte' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('produits.index', ['statut' => 'actif']));

        $response->assertStatus(200);
        $produits = $response->original->getData()['page']['props']['produits'];
        $this->assertCount(1, $produits);
        $this->assertSame('Actif', $produits[0]['nom']);
    }

    public function test_index_recherche_par_reference(): void
    {
        $cible = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit cible',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
        ]);
        app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Autre produit',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
        ]);
        $reference = $cible->variantes->first()->sku;

        $response = $this->actingAs($this->user)
            ->get(route('produits.index', ['search' => $reference]));

        $response->assertStatus(200);
        $produits = $response->original->getData()['page']['props']['produits'];
        $this->assertCount(1, $produits);
        $this->assertSame('Produit cible', $produits[0]['nom']);
    }

    public function test_index_recherche_par_code_barres(): void
    {
        $cible = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Produit scanné',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
            'code_barres' => '3274080005003',
        ]);
        app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Autre produit scanné',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 100,
            'prix_vente' => 200,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('produits.index', ['search' => '3274080005003']));

        $response->assertStatus(200);
        $produits = $response->original->getData()['page']['props']['produits'];
        $this->assertCount(1, $produits);
        $this->assertSame('Produit scanné', $produits[0]['nom']);
    }

    public function test_index_inclut_sites_et_options(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('produits.index'));

        $response->assertStatus(200);
        $props = $response->original->getData()['page']['props'];
        $this->assertArrayHasKey('sites', $props);
        $this->assertArrayHasKey('categories', $props);
        $this->assertArrayHasKey('types', $props);
        $this->assertArrayHasKey('statuts', $props);
        $this->assertArrayHasKey('filters', $props);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function test_create_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('produits.create'))
            ->assertStatus(200);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_produit_and_redirects(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Rouleau plastique',
                'type' => 'materiel',
                'statut' => 'actif',
                'prix_achat' => 1000,
                'is_alerte' => false,
            ]);

        $this->assertDatabaseHas('produits', [
            'organization_id' => $this->org->id,
            'nom' => 'Rouleau plastique',
        ]);
        $this->assertDatabaseHas('produit_variantes', [
            'prix_achat' => 1000,
            'is_default' => true,
        ]);
    }

    public function test_store_redirige_vers_la_fiche_produit(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Rouleau plastique redirection',
                'type' => 'materiel',
                'statut' => 'actif',
                'prix_achat' => 1000,
            ]);

        $produit = Produit::where('nom', 'Rouleau plastique redirection')->firstOrFail();
        $response->assertRedirect(route('produits.show', $produit));
    }

    public function test_store_cree_automatiquement_la_variante_par_defaut(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Eau minerale',
                'type' => 'achat_vente',
                'statut' => 'actif',
                'prix_achat' => 3000,
                'prix_vente' => 5000,
            ]);

        // setNomAttribute() ne conserve la casse que sur la 1ère lettre (reste en minuscule).
        $produit = Produit::where('nom', 'Eau minerale')->firstOrFail();
        $this->assertCount(1, $produit->variantes);
        $variante = $produit->variantes->first();
        $this->assertTrue($variante->is_default);
        $this->assertNotEmpty($variante->sku);
    }

    public function test_store_avec_code_barres(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Produit avec code-barres',
                'type' => 'materiel',
                'statut' => 'actif',
                'prix_achat' => 1000,
                'code_barres' => '3274080005003',
            ]);

        $this->assertDatabaseHas('produit_variantes', [
            'code_barres' => '3274080005003',
        ]);
    }

    public function test_store_refuse_un_code_barres_deja_utilise(): void
    {
        app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Premier produit',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 1000,
            'code_barres' => '3274080005003',
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Deuxième produit',
                'type' => 'materiel',
                'statut' => 'actif',
                'prix_achat' => 1000,
                'code_barres' => '3274080005003',
            ]);

        $response->assertSessionHasErrors('code_barres');
    }

    // ── fournisseur ──────────────────────────────────────────────────────────────

    public function test_store_creates_produit_sans_fournisseur(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Produit sans fournisseur',
                'type' => 'materiel',
                'statut' => 'actif',
                'prix_achat' => 1000,
            ]);

        $produit = Produit::where('nom', 'Produit sans fournisseur')->firstOrFail();
        $this->assertNull($produit->fournisseur_id);
    }

    public function test_store_creates_produit_avec_fournisseur(): void
    {
        $fournisseur = $this->makeFournisseur($this->org);

        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Produit avec fournisseur',
                'type' => 'materiel',
                'statut' => 'actif',
                'prix_achat' => 1000,
                'fournisseur_id' => $fournisseur->id,
            ]);

        $produit = Produit::where('nom', 'Produit avec fournisseur')->firstOrFail();
        $this->assertSame($fournisseur->id, $produit->fournisseur_id);
    }

    public function test_store_refuse_un_fournisseur_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $fournisseurAilleurs = $this->makeFournisseur($autreOrg);

        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Produit test isolation',
                'type' => 'materiel',
                'statut' => 'actif',
                'prix_achat' => 1000,
                'fournisseur_id' => $fournisseurAilleurs->id,
            ])
            ->assertSessionHasErrors('fournisseur_id');
    }

    public function test_store_refuse_un_id_de_prestataire_comme_fournisseur(): void
    {
        // Fournisseur et Prestataire sont deux tables/entités distinctes (machiniste,
        // mécanicien, consultant — jamais de "fournisseur" côté Prestataire) : l'id d'un
        // Prestataire ne doit jamais être accepté comme fournisseur_id d'un produit.
        $machiniste = Prestataire::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'phone' => '+224620000010',
            'type' => 'machiniste',
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Produit test type',
                'type' => 'materiel',
                'statut' => 'actif',
                'prix_achat' => 1000,
                'fournisseur_id' => $machiniste->id,
            ])
            ->assertSessionHasErrors('fournisseur_id');
    }

    public function test_update_change_le_fournisseur_du_produit(): void
    {
        $produit = $this->makeProduit($this->org);
        $ancien = $this->makeFournisseur($this->org, ['raison_sociale' => 'Ancien fournisseur', 'phone' => '+224620000011']);
        $produit->update(['fournisseur_id' => $ancien->id]);

        $nouveau = $this->makeFournisseur($this->org, ['raison_sociale' => 'Nouveau fournisseur', 'phone' => '+224620000012']);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), [
                'nom' => $produit->nom,
                'type' => 'materiel',
                'statut' => 'actif',
                'fournisseur_id' => $nouveau->id,
            ]);

        $this->assertSame($nouveau->id, $produit->fresh()->fournisseur_id);
    }

    public function test_update_retire_le_fournisseur_sans_le_supprimer(): void
    {
        $produit = $this->makeProduit($this->org);
        $fournisseur = $this->makeFournisseur($this->org);
        $produit->update(['fournisseur_id' => $fournisseur->id]);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), [
                'nom' => $produit->nom,
                'type' => 'materiel',
                'statut' => 'actif',
                'fournisseur_id' => null,
            ]);

        $this->assertNull($produit->fresh()->fournisseur_id);
        $this->assertDatabaseHas('fournisseurs', ['id' => $fournisseur->id, 'deleted_at' => null]);
    }

    public function test_show_inclut_le_fournisseur(): void
    {
        $produit = $this->makeProduit($this->org);
        $fournisseur = $this->makeFournisseur($this->org);
        $produit->update(['fournisseur_id' => $fournisseur->id]);

        $response = $this->actingAs($this->user)
            ->get(route('produits.show', $produit));

        $props = $response->original->getData()['page']['props'];
        $this->assertSame($fournisseur->id, $props['produit']['fournisseur']['id']);
        $this->assertSame($fournisseur->nom_complet, $props['produit']['fournisseur']['nom_complet']);
    }

    public function test_edit_inclut_la_liste_des_fournisseurs_actifs(): void
    {
        $produit = $this->makeProduit($this->org);
        $actif = $this->makeFournisseur($this->org, ['raison_sociale' => 'Actif', 'phone' => '+224620000013']);
        $this->makeFournisseur($this->org, ['raison_sociale' => 'Inactif', 'phone' => '+224620000014', 'is_active' => false]);

        $response = $this->actingAs($this->user)
            ->get(route('produits.edit', $produit));

        $props = $response->original->getData()['page']['props'];
        $ids = collect($props['fournisseurs'])->pluck('id')->all();
        $this->assertContains($actif->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_store_fails_with_empty_data(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [])
            ->assertSessionHasErrors(['nom', 'type', 'statut']);
    }

    public function test_store_fails_with_invalid_type(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Test',
                'type' => 'type_invalide',
                'statut' => 'actif',
            ])
            ->assertSessionHasErrors('type');
    }

    public function test_store_fails_sans_prix_requis_pour_le_type(): void
    {
        // materiel exige prix_achat (ProduitType::requiredPrices()) — désormais appliqué
        // via ProduitService::validerPrixSelonType(), contrairement à avant refonte.
        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'Sans prix',
                'type' => 'materiel',
                'statut' => 'actif',
            ])
            ->assertSessionHasErrors('type');

        $this->assertDatabaseMissing('produits', ['nom' => 'Sans prix']);
    }

    public function test_store_avec_options_genere_les_variantes(): void
    {
        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'T-shirt test',
                'type' => 'achat_vente',
                'statut' => 'actif',
                'prix_achat' => 40000,
                'prix_vente' => 75000,
                'options' => [
                    ['nom' => 'Couleur', 'valeurs' => ['Noir', 'Blanc']],
                    ['nom' => 'Taille', 'valeurs' => ['S', 'M']],
                ],
            ]);

        $produit = Produit::where('nom', 'T-shirt test')->firstOrFail();
        $this->assertCount(4, $produit->variantes); // 2 couleurs × 2 tailles
        $this->assertSame(0, $produit->variantes->where('is_default', true)->count());
    }

    public function test_store_avec_option_catalogue_id_enrichit_le_catalogue_sans_dupliquer(): void
    {
        $option = OptionCatalogue::create(['organization_id' => $this->org->id, 'nom' => 'Couleur']);
        $option->valeurs()->create(['valeur' => 'Noir', 'position' => 0]);

        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'T-shirt catalogue',
                'type' => 'achat_vente',
                'statut' => 'actif',
                'prix_achat' => 40000,
                'prix_vente' => 75000,
                'options' => [
                    ['nom' => 'Couleur', 'valeurs' => ['Noir', 'Rouge'], 'option_catalogue_id' => $option->id],
                ],
            ]);

        // "Noir" existait déjà (pas de doublon), "Rouge" est ajouté au catalogue réutilisable.
        $option->refresh();
        $this->assertCount(2, $option->valeurs);
        $this->assertSame(['Noir', 'Rouge'], $option->valeurs->pluck('valeur')->all());
    }

    public function test_store_options_catalogue_id_dune_autre_organisation_est_rejete(): void
    {
        $otherOrg = Organization::factory()->create();
        $optionAutreOrg = OptionCatalogue::create(['organization_id' => $otherOrg->id, 'nom' => 'Couleur']);

        $this->actingAs($this->user)
            ->post(route('produits.store'), [
                'nom' => 'T-shirt test',
                'type' => 'achat_vente',
                'statut' => 'actif',
                'prix_achat' => 40000,
                'prix_vente' => 75000,
                'options' => [
                    ['nom' => 'Couleur', 'valeurs' => ['Noir'], 'option_catalogue_id' => $optionAutreOrg->id],
                ],
            ])
            ->assertSessionHasErrors('options.0.option_catalogue_id');
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_200_for_authorized_user(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->get(route('produits.show', $produit))
            ->assertStatus(200);
    }

    public function test_show_inclut_stocks_par_site(): void
    {
        $produit = $this->makeProduit($this->org);
        $site = $this->defaultSite();

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->varianteId($produit),
            'site_id' => $site->id,
            'qte_stock' => 30,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('produits.show', $produit));

        $response->assertStatus(200);
        $props = $response->original->getData()['page']['props'];
        $this->assertCount(1, $props['produit']['stocks_par_site']);
        $this->assertSame(30, $props['produit']['stocks_par_site'][0]['qte_stock']);
    }

    public function test_show_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $produit = $this->makeProduit($otherOrg);

        $this->actingAs($this->user)
            ->get(route('produits.show', $produit))
            ->assertStatus(403);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function test_edit_returns_200_for_authorized_user(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->get(route('produits.edit', $produit))
            ->assertStatus(200);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_produit_and_redirects(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), [
                'nom' => 'Nouveau nom produit',
                'type' => 'materiel',
                'statut' => 'actif',
                'is_alerte' => false,
            ])
            ->assertRedirect(route('produits.show', $produit));

        $this->assertDatabaseHas('produits', [
            'id' => $produit->id,
            'organization_id' => $this->org->id,
            'nom' => 'Nouveau nom produit',
        ]);
    }

    public function test_update_sans_toucher_au_prix_ne_declenche_pas_derreur(): void
    {
        // Mise à jour partielle qui ne renvoie pas prix_achat : ne doit pas être rejetée
        // comme si le prix (déjà présent sur la variante) était manquant.
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), [
                'nom' => 'Nom modifié',
                'type' => 'materiel',
                'statut' => 'actif',
            ])
            ->assertSessionDoesntHaveErrors();

        $variante = $produit->fresh()->variantePrincipale()->first();
        $this->assertSame(500, $variante->prix_achat);
    }

    public function test_update_conserve_le_meme_code_barres_sans_conflit(): void
    {
        // Renvoyer sur update le code-barres déjà présent sur SA PROPRE variante ne doit
        // jamais être rejeté comme "déjà utilisé" — Rule::unique(...)->ignore() doit exclure
        // la variante éditée elle-même.
        $produit = $this->makeProduit($this->org);
        $produit->variantePrincipale()->first()->update(['code_barres' => '3274080005003']);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), [
                'nom' => 'Produit test',
                'type' => 'materiel',
                'statut' => 'actif',
                'code_barres' => '3274080005003',
            ])
            ->assertSessionDoesntHaveErrors();
    }

    public function test_update_refuse_un_code_barres_dun_autre_produit(): void
    {
        $autre = $this->makeProduit($this->org);
        $autre->variantePrincipale()->first()->update(['code_barres' => '3274080005003']);

        $produit = app(ProduitService::class)->creer([
            'organization_id' => $this->org->id,
            'nom' => 'Deuxième produit',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_achat' => 500,
        ]);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), [
                'nom' => 'Deuxième produit',
                'type' => 'materiel',
                'statut' => 'actif',
                'code_barres' => '3274080005003',
            ])
            ->assertSessionHasErrors('code_barres');
    }

    public function test_update_fails_with_missing_required_fields(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->put(route('produits.update', $produit), [])
            ->assertSessionHasErrors(['nom', 'type', 'statut']);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_produit_and_redirects(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->delete(route('produits.destroy', $produit))
            ->assertRedirect(route('produits.index'));

        $this->assertSoftDeleted('produits', ['id' => $produit->id]);
    }

    public function test_destroy_returns_403_for_other_organization(): void
    {
        $otherOrg = Organization::factory()->create();
        $produit = $this->makeProduit($otherOrg);

        $this->actingAs($this->user)
            ->delete(route('produits.destroy', $produit))
            ->assertStatus(403);
    }

    // ── ajuster-stock ─────────────────────────────────────────────────────────

    public function test_ajuster_stock_augmente_le_stock(): void
    {
        $produit = $this->makeProduit($this->org, 50);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 20,
                'motif_type' => 'apres_production',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produits', [
            'id' => $produit->id,
            'qte_stock' => 70,
        ]);

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_variante_id' => $this->varianteId($produit),
            'site_id' => $site->id,
            'type' => 'entree',
            'quantite' => 20,
            'stock_avant' => 50,
            'stock_apres' => 70,
        ]);
    }

    public function test_ajuster_stock_diminue_le_stock(): void
    {
        $produit = $this->makeProduit($this->org, 50);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'diminuer' => 15,
                'motif_type' => 'perte',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produits', [
            'id' => $produit->id,
            'qte_stock' => 35,
        ]);

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_variante_id' => $this->varianteId($produit),
            'site_id' => $site->id,
            'type' => 'sortie',
            'quantite' => 15,
            'stock_avant' => 50,
            'stock_apres' => 35,
        ]);
    }

    public function test_ajuster_stock_cree_variante_stock_par_site(): void
    {
        $produit = $this->makeProduit($this->org, 50);
        $site = $this->defaultSite();
        $varianteId = $this->varianteId($produit);

        $this->assertDatabaseMissing('variante_stocks', ['produit_variante_id' => $varianteId]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $varianteId,
            'site_id' => $site->id,
            'qte_stock' => 60, // 50 migré + 10 ajout
        ]);
    }

    public function test_ajuster_stock_agrege_stock_total_sur_plusieurs_sites(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $varianteId = $this->varianteId($produit);
        $site1 = $this->defaultSite();
        $site2 = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site 2',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $varianteId,
            'site_id' => $site1->id,
            'qte_stock' => 30,
        ]);
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $varianteId,
            'site_id' => $site2->id,
            'qte_stock' => 20,
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site1->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produits', ['id' => $produit->id, 'qte_stock' => 60]);
        $this->assertDatabaseHas('variante_stocks', ['produit_variante_id' => $varianteId, 'site_id' => $site1->id, 'qte_stock' => 40]);
        $this->assertDatabaseHas('variante_stocks', ['produit_variante_id' => $varianteId, 'site_id' => $site2->id, 'qte_stock' => 20]);
    }

    public function test_ajuster_stock_enregistre_le_motif(): void
    {
        $produit = $this->makeProduit($this->org);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('mouvements_stock', [
            'produit_variante_id' => $this->varianteId($produit),
            'notes' => 'Correction de stock',
        ]);
    }

    public function test_ajuster_stock_echoue_sans_site_id(): void
    {
        $produit = $this->makeProduit($this->org);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('site_id');
    }

    public function test_ajuster_stock_echoue_avec_site_autre_organisation(): void
    {
        $produit = $this->makeProduit($this->org);
        $otherOrg = Organization::factory()->create();
        $otherSite = Site::create([
            'organization_id' => $otherOrg->id,
            'nom' => 'Site autre org',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $otherSite->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertStatus(404);
    }

    public function test_ajuster_stock_echoue_si_deux_champs_renseignes(): void
    {
        $produit = $this->makeProduit($this->org);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 10,
                'diminuer' => 5,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('augmenter');
    }

    public function test_ajuster_stock_echoue_si_aucun_champ_renseigne(): void
    {
        $produit = $this->makeProduit($this->org);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('augmenter');
    }

    public function test_ajuster_stock_echoue_si_quantite_nulle_ou_negative(): void
    {
        $produit = $this->makeProduit($this->org);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 0,
            ])
            ->assertSessionHasErrors('augmenter');

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'diminuer' => -5,
            ])
            ->assertSessionHasErrors('diminuer');
    }

    public function test_ajuster_stock_accepte_apres_achat_en_augmentation(): void
    {
        $produit = $this->makeProduit($this->org, 10);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 5,
                'motif_type' => 'apres_achat',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produits', ['id' => $produit->id, 'qte_stock' => 15]);
    }

    public function test_ajuster_stock_refuse_apres_achat_en_diminution(): void
    {
        $produit = $this->makeProduit($this->org, 10);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'diminuer' => 5,
                'motif_type' => 'apres_achat',
            ])
            ->assertSessionHasErrors('motif_type');
    }

    public function test_ajuster_stock_echoue_si_retrait_depasse_stock_du_site(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $varianteId = $this->varianteId($produit);
        $site = $this->defaultSite();

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $varianteId,
            'site_id' => $site->id,
            'qte_stock' => 50,
        ]);
        $produit->update(['qte_stock' => 50]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'diminuer' => 100,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('diminuer');

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $varianteId,
            'site_id' => $site->id,
            'qte_stock' => 50,
        ]);
    }

    public function test_ajuster_stock_retourne_403_pour_autre_organisation(): void
    {
        $otherOrg = Organization::factory()->create();
        $produit = $this->makeProduit($otherOrg);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 10,
            ])
            ->assertStatus(403);
    }

    public function test_ajuster_stock_ne_cree_pas_mouvement_si_validation_echoue(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $varianteId = $this->varianteId($produit);
        $site = $this->defaultSite();

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $varianteId,
            'site_id' => $site->id,
            'qte_stock' => 50,
        ]);

        $countBefore = MouvementStock::where('produit_variante_id', $varianteId)->count();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'diminuer' => 9999,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('diminuer');

        $this->assertSame($countBefore, MouvementStock::where('produit_variante_id', $varianteId)->count());
    }

    // ── historique ────────────────────────────────────────────────────────────

    public function test_historique_retourne_ajustements_et_modifications(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site = $this->defaultSite();

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->varianteId($produit),
            'site_id' => $site->id,
            'qte_stock' => 50,
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('produits.historique', $produit));

        $response->assertStatus(200)
            ->assertJsonStructure(['ajustements', 'modifications']);

        $this->assertNotEmpty($response->json('ajustements'));
    }

    // ── Sécurité multi-agences ────────────────────────────────────────────────

    public function test_admin_peut_ajuster_stock_sur_nimporte_quel_site(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $varianteId = $this->varianteId($produit);

        $autresSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site secondaire',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $varianteId,
            'site_id' => $autresSite->id,
            'qte_stock' => 20,
        ]);

        // $this->user est admin_entreprise (via makeUserWithPermissions)
        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $autresSite->id,
                'augmenter' => 5,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $varianteId,
            'site_id' => $autresSite->id,
            'qte_stock' => 25,
        ]);
    }

    public function test_non_admin_peut_ajuster_stock_de_son_site(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $varianteId = $this->varianteId($produit);
        $site = $this->defaultSite();

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $varianteId,
            'site_id' => $site->id,
            'qte_stock' => 30,
        ]);

        $employe = $this->makeNonAdminUserOnSite($site);

        DroitAjustementStock::create([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_augmenter' => true,
            'peut_diminuer' => true,
        ]);

        $this->actingAs($employe)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $varianteId,
            'site_id' => $site->id,
            'qte_stock' => 40,
        ]);
    }

    public function test_non_admin_ne_peut_pas_ajuster_stock_dun_autre_site(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $varianteId = $this->varianteId($produit);
        $siteEmploye = $this->defaultSite();

        $siteInterdit = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site interdit',
            'type' => 'depot',
            'localisation' => 'Labé',
        ]);

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $varianteId,
            'site_id' => $siteInterdit->id,
            'qte_stock' => 50,
        ]);

        $employe = $this->makeNonAdminUserOnSite($siteEmploye);

        $this->actingAs($employe)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $siteInterdit->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ])
            ->assertStatus(403);

        // Le stock ne doit pas avoir changé
        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $varianteId,
            'site_id' => $siteInterdit->id,
            'qte_stock' => 50,
        ]);
    }

    public function test_ajustement_modifie_uniquement_le_site_selectionne(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $varianteId = $this->varianteId($produit);
        $site1 = $this->defaultSite();
        $site2 = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site 2',
            'type' => 'depot',
            'localisation' => 'Mamou',
        ]);

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $varianteId,
            'site_id' => $site1->id,
            'qte_stock' => 100,
        ]);
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $varianteId,
            'site_id' => $site2->id,
            'qte_stock' => 200,
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site1->id,
                'augmenter' => 50,
                'motif_type' => 'correction_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $varianteId,
            'site_id' => $site1->id,
            'qte_stock' => 150,
        ]);
        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $varianteId,
            'site_id' => $site2->id,
            'qte_stock' => 200,
        ]);
    }

    public function test_historique_modifications_exclut_stock_adjusted(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site = $this->defaultSite();

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->varianteId($produit),
            'site_id' => $site->id,
            'qte_stock' => 50,
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 5,
                'motif_type' => 'correction_stock',
            ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('produits.historique', $produit));

        $response->assertOk();
        $eventCodes = array_column($response->json('modifications'), 'event_code');
        $this->assertNotContains('stock_adjusted', $eventCodes);
    }

    // ── Indicateur de tendance stock ──────────────────────────────────────────

    public function test_index_inclut_last_mouvement_entree(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site = $this->defaultSite();

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->varianteId($produit),
            'site_id' => $site->id,
            'qte_stock' => 30,
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 15,
                'motif_type' => 'correction_stock',
            ]);

        $response = $this->actingAs($this->user)->get(route('produits.index'));
        $response->assertStatus(200);

        $produits = $response->original->getData()['page']['props']['produits'];
        $found = collect($produits)->firstWhere('id', $produit->id);

        $this->assertNotNull($found);
        $this->assertSame('entree', $found['last_mouvement_type']);
        $this->assertSame(15, $found['last_mouvement_quantite']);
    }

    public function test_index_inclut_last_mouvement_sortie(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site = $this->defaultSite();

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->varianteId($produit),
            'site_id' => $site->id,
            'qte_stock' => 100,
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'diminuer' => 20,
                'motif_type' => 'correction_stock',
            ]);

        $response = $this->actingAs($this->user)->get(route('produits.index'));
        $response->assertStatus(200);

        $produits = $response->original->getData()['page']['props']['produits'];
        $found = collect($produits)->firstWhere('id', $produit->id);

        $this->assertNotNull($found);
        $this->assertSame('sortie', $found['last_mouvement_type']);
        $this->assertSame(20, $found['last_mouvement_quantite']);
    }

    public function test_index_last_mouvement_null_si_aucun_ajustement(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $site = $this->defaultSite();

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->varianteId($produit),
            'site_id' => $site->id,
            'qte_stock' => 10,
        ]);

        $response = $this->actingAs($this->user)->get(route('produits.index'));
        $response->assertStatus(200);

        $produits = $response->original->getData()['page']['props']['produits'];
        $found = collect($produits)->firstWhere('id', $produit->id);

        $this->assertNotNull($found);
        $this->assertNull($found['last_mouvement_type']);
        $this->assertNull($found['last_mouvement_quantite']);
    }

    public function test_index_last_mouvement_filtre_par_site(): void
    {
        $produit = $this->makeProduit($this->org, 0);
        $varianteId = $this->varianteId($produit);
        $site1 = $this->defaultSite();
        $site2 = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site 2',
            'type' => 'depot',
            'localisation' => 'Mamou',
        ]);

        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $varianteId,
            'site_id' => $site1->id,
            'qte_stock' => 50,
        ]);
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $varianteId,
            'site_id' => $site2->id,
            'qte_stock' => 50,
        ]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site1->id,
                'augmenter' => 10,
                'motif_type' => 'correction_stock',
            ]);

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site2->id,
                'diminuer' => 5,
                'motif_type' => 'correction_stock',
            ]);

        $response = $this->actingAs($this->user)
            ->get(route('produits.index', ['site_ids' => [$site1->id]]));

        $produits = $response->original->getData()['page']['props']['produits'];
        $found = collect($produits)->firstWhere('id', $produit->id);

        $this->assertNotNull($found);
        $this->assertSame('entree', $found['last_mouvement_type']);
        $this->assertSame(10, $found['last_mouvement_quantite']);
    }

    // ── updateVariante ───────────────────────────────────────────────────────

    /** Produit à déclinaisons (2 variantes : Noir / Blanc) via le vrai chemin de création. */
    private function makeProduitDecline(Organization $org): Produit
    {
        return app(ProduitService::class)->creer([
            'organization_id' => $org->id,
            'nom' => 'T-shirt test',
            'type' => 'achat_vente',
            'statut' => 'actif',
            'prix_achat' => 1000,
            'prix_vente' => 2000,
            'options' => [
                ['nom' => 'Couleur', 'valeurs' => ['Noir', 'Blanc']],
            ],
        ])->fresh(['variantes']);
    }

    public function test_update_variante_modifie_le_prix_et_redirige(): void
    {
        $produit = $this->makeProduitDecline($this->org);
        $variante = $produit->variantes->first();

        $this->actingAs($this->user)
            ->put(route('produits.variantes.update', [$produit, $variante]), [
                'prix_vente' => 2500,
                'prix_achat' => 1200,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produit_variantes', [
            'id' => $variante->id,
            'prix_vente' => 2500,
            'prix_achat' => 1200,
        ]);
    }

    public function test_update_variante_peut_desactiver_la_variante(): void
    {
        $produit = $this->makeProduitDecline($this->org);
        $variante = $produit->variantes->first();

        $this->actingAs($this->user)
            ->put(route('produits.variantes.update', [$produit, $variante]), [
                'prix_vente' => (int) $variante->prix_vente,
                'prix_achat' => (int) $variante->prix_achat,
                'is_active' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produit_variantes', [
            'id' => $variante->id,
            'is_active' => false,
        ]);
    }

    public function test_update_variante_refuse_variante_dun_autre_produit(): void
    {
        $produit = $this->makeProduitDecline($this->org);
        $autreProduit = $this->makeProduitDecline($this->org);
        $varianteAutreProduit = $autreProduit->variantes->first();

        $this->actingAs($this->user)
            ->put(route('produits.variantes.update', [$produit, $varianteAutreProduit]), [
                'prix_vente' => 3000,
            ])
            ->assertStatus(404);
    }

    public function test_update_variante_retourne_403_pour_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $produit = $this->makeProduitDecline($autreOrg);
        $variante = $produit->variantes->first();

        $this->actingAs($this->user)
            ->put(route('produits.variantes.update', [$produit, $variante]), [
                'prix_vente' => 3000,
            ])
            ->assertStatus(403);
    }

    public function test_update_variante_echoue_si_type_exige_un_prix_manquant(): void
    {
        $produit = $this->makeProduitDecline($this->org);
        $variante = $produit->variantes->first();

        $this->actingAs($this->user)
            ->put(route('produits.variantes.update', [$produit, $variante]), [
                'prix_vente' => null,
                'prix_achat' => null,
            ])
            ->assertSessionHasErrors('type');
    }

    // ── ajusterStock : variante obligatoire pour un produit à déclinaisons ──────

    public function test_ajuster_stock_exige_une_variante_pour_un_produit_a_declinaisons(): void
    {
        $produit = $this->makeProduitDecline($this->org);
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'augmenter' => 5,
                'motif_type' => 'correction_stock',
            ])
            ->assertSessionHasErrors('variante_id');
    }

    public function test_ajuster_stock_avec_variante_najuste_que_cette_variante(): void
    {
        $produit = $this->makeProduitDecline($this->org);
        [$varianteA, $varianteB] = $produit->variantes->all();
        $site = $this->defaultSite();

        $this->actingAs($this->user)
            ->post(route('produits.ajuster-stock', $produit), [
                'site_id' => $site->id,
                'variante_id' => $varianteA->id,
                'augmenter' => 7,
                'motif_type' => 'apres_achat',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $varianteA->id,
            'site_id' => $site->id,
            'qte_stock' => 7,
        ]);
        $this->assertDatabaseMissing('variante_stocks', [
            'produit_variante_id' => $varianteB->id,
            'site_id' => $site->id,
        ]);
    }

    // ── variantesIndex / variantesBulkUpdate (éditeur groupé) ────────────────────

    public function test_variantes_index_returns_200_for_authorized_user(): void
    {
        $produit = $this->makeProduitDecline($this->org);

        $this->actingAs($this->user)
            ->get(route('produits.variantes.index', $produit))
            ->assertStatus(200);
    }

    public function test_variantes_index_returns_403_for_other_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        $produit = $this->makeProduitDecline($autreOrg);

        $this->actingAs($this->user)
            ->get(route('produits.variantes.index', $produit))
            ->assertStatus(403);
    }

    public function test_bulk_update_modifie_plusieurs_variantes(): void
    {
        $produit = $this->makeProduitDecline($this->org);
        [$varianteA, $varianteB] = $produit->variantes->all();

        $this->actingAs($this->user)
            ->put(route('produits.variantes.bulk-update', $produit), [
                'variantes' => [
                    ['id' => $varianteA->id, 'prix_vente' => 3000, 'is_active' => true],
                    ['id' => $varianteB->id, 'prix_vente' => 3500, 'is_active' => false],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produit_variantes', ['id' => $varianteA->id, 'prix_vente' => 3000, 'is_active' => true]);
        $this->assertDatabaseHas('produit_variantes', ['id' => $varianteB->id, 'prix_vente' => 3500, 'is_active' => false]);
    }

    public function test_bulk_update_ne_touche_pas_le_sku(): void
    {
        $produit = $this->makeProduitDecline($this->org);
        $variante = $produit->variantes->first();
        $skuOriginal = $variante->sku;

        $this->actingAs($this->user)
            ->put(route('produits.variantes.bulk-update', $produit), [
                'variantes' => [
                    ['id' => $variante->id, 'prix_vente' => 4000],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('produit_variantes', ['id' => $variante->id, 'sku' => $skuOriginal]);
    }

    public function test_bulk_update_echoue_si_type_exige_un_prix_manquant(): void
    {
        $produit = $this->makeProduitDecline($this->org);
        $variante = $produit->variantes->first();

        $this->actingAs($this->user)
            ->put(route('produits.variantes.bulk-update', $produit), [
                'variantes' => [
                    ['id' => $variante->id, 'prix_vente' => null, 'prix_achat' => null],
                ],
            ])
            ->assertSessionHasErrors('type');
    }

    public function test_bulk_update_refuse_une_variante_dun_autre_produit(): void
    {
        $produit = $this->makeProduitDecline($this->org);
        $autreProduit = $this->makeProduitDecline($this->org);
        $varianteAutreProduit = $autreProduit->variantes->first();

        $this->actingAs($this->user)
            ->put(route('produits.variantes.bulk-update', $produit), [
                'variantes' => [
                    ['id' => $varianteAutreProduit->id, 'prix_vente' => 5000],
                ],
            ])
            ->assertStatus(404);
    }

    public function test_bulk_update_returns_403_for_other_organization(): void
    {
        $autreOrg = Organization::factory()->create();
        $produit = $this->makeProduitDecline($autreOrg);
        $variante = $produit->variantes->first();

        $this->actingAs($this->user)
            ->put(route('produits.variantes.bulk-update', $produit), [
                'variantes' => [
                    ['id' => $variante->id, 'prix_vente' => 5000],
                ],
            ])
            ->assertStatus(403);
    }
}
