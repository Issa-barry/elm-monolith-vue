<?php

namespace Tests\Unit;

use App\Enums\SiteType;
use App\Services\SiteNamingService;
use Tests\TestCase;

class SiteNamingServiceTest extends TestCase
{
    public function test_usine_de_matoto(): void
    {
        $nom = app(SiteNamingService::class)->generateName(SiteType::USINE, 'Matoto');

        $this->assertSame('Usine de Matoto', $nom);
    }

    public function test_ne_produit_jamais_un_type_duplique(): void
    {
        $nom = app(SiteNamingService::class)->generateName(SiteType::USINE, 'Matoto');

        $this->assertNotSame('Usine de Usine Matoto', $nom);
        $this->assertSame(1, substr_count($nom, 'Usine'));
    }

    public function test_boutique_de_sonfonia(): void
    {
        $nom = app(SiteNamingService::class)->generateName(SiteType::BOUTIQUE, 'Sonfonia');

        $this->assertSame('Boutique de Sonfonia', $nom);
    }

    public function test_agence_de_kipe(): void
    {
        $nom = app(SiteNamingService::class)->generateName(SiteType::AGENCE, 'Kipé');

        $this->assertSame('Agence de Kipé', $nom);
    }

    public function test_depot_de_lambanyi(): void
    {
        $nom = app(SiteNamingService::class)->generateName(SiteType::DEPOT, 'Lambanyi');

        $this->assertSame('Dépôt de Lambanyi', $nom);
    }

    /**
     * SiteType::BOUTIQUE->label() vaut "Boutique / Point de vente" — le nom généré doit rester
     * "Boutique de ...", pas répéter le libellé d'affichage complet.
     */
    public function test_le_prefixe_nest_pas_le_libelle_complet_avec_slash(): void
    {
        $nom = app(SiteNamingService::class)->generateName(SiteType::BOUTIQUE, 'Matoto');

        $this->assertSame('Boutique de Matoto', $nom);
        $this->assertStringNotContainsString('Point de vente', $nom);
    }

    public function test_le_quartier_est_normalise_par_un_trim(): void
    {
        $nom = app(SiteNamingService::class)->generateName(SiteType::USINE, '  Matoto  ');

        $this->assertSame('Usine de Matoto', $nom);
    }

    public function test_aucune_numerotation_nest_ajoutee(): void
    {
        $nom = app(SiteNamingService::class)->generateName(SiteType::USINE, 'Matoto');

        $this->assertDoesNotMatchRegularExpression('/\d/', $nom);
    }
}
