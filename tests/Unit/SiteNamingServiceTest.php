<?php

namespace Tests\Unit;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Site;
use App\Services\SiteNamingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteNamingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Organization
    {
        return Organization::create(['name' => 'ELM Test', 'slug' => 'elm-test-'.uniqid(), 'is_active' => true]);
    }

    public function test_premier_site_dun_type_donne_le_numero_1(): void
    {
        $org = $this->makeOrg();

        $nom = app(SiteNamingService::class)->nextName($org->id, SiteType::BOUTIQUE, 'Matoto');

        $this->assertSame('Boutique 1 Matoto', $nom);
    }

    public function test_numerotation_continue_apres_des_boutiques_existantes(): void
    {
        $org = $this->makeOrg();
        Site::create(['organization_id' => $org->id, 'nom' => 'Boutique 1 Matoto', 'type' => SiteType::BOUTIQUE->value]);
        Site::create(['organization_id' => $org->id, 'nom' => 'Boutique 2 Kipé', 'type' => SiteType::BOUTIQUE->value]);

        $nom = app(SiteNamingService::class)->nextName($org->id, SiteType::BOUTIQUE, 'Sonfonia');

        $this->assertSame('Boutique 3 Sonfonia', $nom);
    }

    public function test_numerotation_est_independante_par_type(): void
    {
        $org = $this->makeOrg();
        Site::create(['organization_id' => $org->id, 'nom' => 'Boutique 1 Matoto', 'type' => SiteType::BOUTIQUE->value]);
        Site::create(['organization_id' => $org->id, 'nom' => 'Boutique 2 Kipé', 'type' => SiteType::BOUTIQUE->value]);

        $nom = app(SiteNamingService::class)->nextName($org->id, SiteType::USINE, 'Samgoya');

        $this->assertSame('Usine 1 Samgoya', $nom);
    }

    public function test_numerotation_est_independante_par_organisation(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg();
        Site::create(['organization_id' => $orgA->id, 'nom' => 'Boutique 1 Matoto', 'type' => SiteType::BOUTIQUE->value]);

        $nom = app(SiteNamingService::class)->nextName($orgB->id, SiteType::BOUTIQUE, 'Kipé');

        $this->assertSame('Boutique 1 Kipé', $nom);
    }

    public function test_le_prefixe_de_nommage_nest_pas_le_libelle_complet_avec_slash(): void
    {
        // SiteType::BOUTIQUE->label() vaut "Boutique / Point de vente" — le nom généré doit
        // rester "Boutique N ...", pas répéter le libellé d'affichage complet.
        $org = $this->makeOrg();

        $nom = app(SiteNamingService::class)->nextName($org->id, SiteType::BOUTIQUE, 'Matoto');

        $this->assertStringStartsWith('Boutique 1', $nom);
        $this->assertStringNotContainsString('Point de vente', $nom);
    }

    public function test_contourne_une_collision_de_nom_existant(): void
    {
        $org = $this->makeOrg();
        // "Usine 1 Samgoya" existe déjà (ex: renommé manuellement) sans qu'il y ait réellement
        // une seule Usine comptée pour ce type — le service doit quand même éviter le doublon.
        Site::create(['organization_id' => $org->id, 'nom' => 'Usine 1 Samgoya', 'type' => SiteType::DEPOT->value]);

        $nom = app(SiteNamingService::class)->nextName($org->id, SiteType::USINE, 'Samgoya');

        $this->assertSame('Usine 2 Samgoya', $nom);
    }
}
