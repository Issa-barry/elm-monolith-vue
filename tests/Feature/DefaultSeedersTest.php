<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\OptionCatalogue;
use App\Models\OptionCatalogueValeur;
use App\Models\Organization;
use Database\Seeders\CategorieDefaultSeeder;
use Database\Seeders\OptionCatalogueDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultSeedersTest extends TestCase
{
    use RefreshDatabase;

    // ── OptionCatalogueDefaultSeeder ─────────────────────────────────────────────

    public function test_seede_les_trois_options_systeme_avec_leurs_valeurs(): void
    {
        $org = Organization::factory()->create();

        OptionCatalogueDefaultSeeder::seedPourOrganisation($org->id);

        $noms = OptionCatalogue::where('organization_id', $org->id)->pluck('nom')->sort()->values()->all();
        $this->assertSame(['Couleur', 'Pointure', 'Taille'], $noms);

        $couleur = OptionCatalogue::where('organization_id', $org->id)->where('nom', 'Couleur')->firstOrFail();
        $this->assertTrue($couleur->is_system);
        $this->assertGreaterThanOrEqual(5, $couleur->valeurs()->count());
        $this->assertTrue($couleur->valeurs()->where('valeur', 'Noir')->exists());

        $taille = OptionCatalogue::where('organization_id', $org->id)->where('nom', 'Taille')->firstOrFail();
        $this->assertTrue($taille->valeurs()->where('valeur', 'XL')->exists());

        $pointure = OptionCatalogue::where('organization_id', $org->id)->where('nom', 'Pointure')->firstOrFail();
        $this->assertTrue($pointure->valeurs()->where('valeur', '40')->exists());
    }

    public function test_options_systeme_idempotent_sans_doublon(): void
    {
        $org = Organization::factory()->create();

        OptionCatalogueDefaultSeeder::seedPourOrganisation($org->id);
        $nbOptionsAvant = OptionCatalogue::where('organization_id', $org->id)->count();
        $nbValeursAvant = OptionCatalogueValeur::whereHas(
            'optionCatalogue',
            fn ($q) => $q->where('organization_id', $org->id)
        )->count();

        OptionCatalogueDefaultSeeder::seedPourOrganisation($org->id);

        $this->assertSame($nbOptionsAvant, OptionCatalogue::where('organization_id', $org->id)->count());
        $this->assertSame(
            $nbValeursAvant,
            OptionCatalogueValeur::whereHas('optionCatalogue', fn ($q) => $q->where('organization_id', $org->id))->count()
        );
    }

    public function test_marque_systeme_une_option_deja_creee_manuellement_sans_la_dupliquer(): void
    {
        $org = Organization::factory()->create();
        $couleur = OptionCatalogue::create(['organization_id' => $org->id, 'nom' => 'Couleur']);
        $couleur->valeurs()->create(['valeur' => 'Turquoise', 'position' => 0]);

        OptionCatalogueDefaultSeeder::seedPourOrganisation($org->id);

        $this->assertSame(1, OptionCatalogue::where('organization_id', $org->id)->where('nom', 'Couleur')->count());
        $couleur->refresh();
        $this->assertTrue($couleur->is_system);
        // La valeur manuelle pré-existante n'est jamais supprimée, uniquement complétée.
        $this->assertTrue($couleur->valeurs()->where('valeur', 'Turquoise')->exists());
        $this->assertTrue($couleur->valeurs()->where('valeur', 'Noir')->exists());
    }

    // ── CategorieDefaultSeeder ────────────────────────────────────────────────────

    public function test_seede_larborescence_par_defaut(): void
    {
        $org = Organization::factory()->create();

        CategorieDefaultSeeder::seedPourOrganisation($org->id);

        $racines = Categorie::where('organization_id', $org->id)->whereNull('parent_id')->pluck('nom')->sort()->values()->all();
        $this->assertSame(['Boissons', 'Chaussures', 'Matériel', 'Vêtements'], $racines);

        $vetements = Categorie::where('organization_id', $org->id)->whereNull('parent_id')->where('nom', 'Vêtements')->firstOrFail();
        $enfants = Categorie::where('organization_id', $org->id)->where('parent_id', $vetements->id)->pluck('nom')->sort()->values()->all();
        $this->assertSame(['Accessoires', 'Chemises', 'Pantalons', 'T-shirts'], $enfants);

        $boissons = Categorie::where('organization_id', $org->id)->whereNull('parent_id')->where('nom', 'Boissons')->firstOrFail();
        $this->assertTrue(Categorie::where('parent_id', $boissons->id)->where('nom', 'Eau')->exists());
    }

    public function test_arborescence_par_defaut_idempotente_sans_doublon(): void
    {
        $org = Organization::factory()->create();

        CategorieDefaultSeeder::seedPourOrganisation($org->id);
        $nbAvant = Categorie::where('organization_id', $org->id)->count();

        CategorieDefaultSeeder::seedPourOrganisation($org->id);

        $this->assertSame($nbAvant, Categorie::where('organization_id', $org->id)->count());
    }

    public function test_categories_par_defaut_isolees_par_organisation(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        CategorieDefaultSeeder::seedPourOrganisation($orgA->id);

        $this->assertSame(0, Categorie::where('organization_id', $orgB->id)->count());
    }
}
