<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Site;
use App\Services\ImportSites\SiteImportParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * cf. le doublon réel observé en usage : un fichier "sites" réimporté avec un nom du type
 * "Cba (Lansanaya, Kountia)" alors qu'un site "CBA" existe déjà — ni ImportTextNormalizer
 * (casse/accents/espaces) ni ReferenceValueResolver::suggestClosest() (Levenshtein, distance
 * max 2) ne rapprochent ces deux chaînes, donc aucun blocage ni avertissement n'apparaissait
 * avant SiteImportParser::trouverSiteProche() — d'où la création silencieuse d'un doublon.
 */
class SiteImportParserTest extends TestCase
{
    use RefreshDatabase;

    private function ligne(array $overrides = []): Collection
    {
        return collect(array_replace([
            'nom' => 'Nouveau Site',
            'type' => 'Dépôt',
            'ville_obligatoire' => 'Conakry',
            'quartier_obligatoire' => 'Quelquepart',
            'telephone_obligatoire' => '+224620000000',
            'description_facultatif' => '',
            'site_parent_facultatif' => '',
            'longitude_facultatif' => '',
            'latitude_facultatif' => '',
        ], $overrides));
    }

    public function test_signale_un_doublon_par_prefixe_sans_bloquer_limport(): void
    {
        $org = Organization::factory()->create();
        Site::create(['organization_id' => $org->id, 'nom' => 'CBA', 'type' => 'usine', 'ville' => 'Conakry', 'quartier' => 'Kountia', 'telephone' => '+224626078393']);

        $resultat = (new SiteImportParser)->analyser(
            collect([$this->ligne(['nom' => 'Cba (Lansanaya, Kountia)', 'type' => 'Usine'])]),
            $org->id,
        );

        $ligne = $resultat['lignes'][0];
        $this->assertSame('nouveau', $ligne['statut']);
        $this->assertEmpty($ligne['erreurs']);
        $this->assertNotEmpty($ligne['avertissements']);
        $this->assertStringContainsString('CBA', $ligne['avertissements'][0]);
    }

    public function test_signale_un_doublon_par_faute_de_frappe_via_levenshtein(): void
    {
        $org = Organization::factory()->create();
        Site::create(['organization_id' => $org->id, 'nom' => 'Sonfonia', 'type' => 'depot', 'ville' => 'Conakry', 'quartier' => 'Sonfonia', 'telephone' => '+224624300206']);

        $resultat = (new SiteImportParser)->analyser(
            collect([$this->ligne(['nom' => 'Sonfona'])]),
            $org->id,
        );

        $this->assertNotEmpty($resultat['lignes'][0]['avertissements']);
    }

    public function test_naucun_avertissement_pour_un_nom_vraiment_different(): void
    {
        $org = Organization::factory()->create();
        Site::create(['organization_id' => $org->id, 'nom' => 'CBA', 'type' => 'usine', 'ville' => 'Conakry', 'quartier' => 'Kountia', 'telephone' => '+224626078393']);

        $resultat = (new SiteImportParser)->analyser(
            collect([$this->ligne(['nom' => 'Tombolia'])]),
            $org->id,
        );

        $this->assertSame('nouveau', $resultat['lignes'][0]['statut']);
        $this->assertEmpty($resultat['lignes'][0]['avertissements']);
    }

    public function test_un_site_exactement_existant_nest_jamais_signale(): void
    {
        $org = Organization::factory()->create();
        Site::create(['organization_id' => $org->id, 'nom' => 'CBA', 'type' => 'usine', 'ville' => 'Conakry', 'quartier' => 'Kountia', 'telephone' => '+224626078393']);

        $resultat = (new SiteImportParser)->analyser(
            collect([$this->ligne(['nom' => 'cba', 'type' => 'Usine'])]),
            $org->id,
        );

        $this->assertSame('existant', $resultat['lignes'][0]['statut']);
        $this->assertEmpty($resultat['lignes'][0]['avertissements']);
    }
}
