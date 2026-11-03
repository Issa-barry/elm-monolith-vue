<?php

namespace Tests\Feature\Pwa;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManifestPwaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (['PWA_NAME', 'PWA_SHORT_NAME'] as $variable) {
            unset($_SERVER[$variable], $_ENV[$variable]);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function chargerConfigPwa(?string $name, ?string $shortName): array
    {
        foreach (['PWA_NAME' => $name, 'PWA_SHORT_NAME' => $shortName] as $variable => $valeur) {
            unset($_SERVER[$variable], $_ENV[$variable]);

            if ($valeur !== null) {
                $_SERVER[$variable] = $valeur;
                $_ENV[$variable] = $valeur;
            }
        }

        return require config_path('pwa.php');
    }

    public function test_le_manifest_public_utilise_les_noms_configures(): void
    {
        config(['pwa.name' => 'ELM Admin Test', 'pwa.short_name' => 'ELM Admin T']);

        $response = $this->get('/manifest.json');

        $response->assertOk()
            ->assertJsonPath('name', 'ELM Admin Test')
            ->assertJsonPath('short_name', 'ELM Admin T')
            ->assertJsonPath('id', '/')
            ->assertJsonPath('start_url', '/')
            ->assertJsonPath('scope', '/')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonCount(3, 'icons');

        $this->assertStringContainsString('application/manifest+json', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
    }

    public function test_le_manifest_ne_depend_pas_du_nom_de_l_application(): void
    {
        config(['app.name' => 'Autre nom', 'pwa.name' => 'ELM Admin', 'pwa.short_name' => 'ELM Admin']);

        $this->get('/manifest.json')
            ->assertJsonPath('name', 'ELM Admin')
            ->assertJsonPath('short_name', 'ELM Admin');
    }

    public function test_le_manifest_ne_cree_ni_session_ni_cookie(): void
    {
        $response = $this->get('/manifest.json');

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_les_variables_d_environnement_alimentent_la_configuration(): void
    {
        $this->assertSame(
            ['name' => 'ELM Admin Test', 'short_name' => 'ELM Admin T'],
            $this->chargerConfigPwa('ELM Admin Test', 'ELM Admin T'),
        );
    }

    public function test_le_nom_court_reprend_le_nom_complet_s_il_est_absent_ou_vide(): void
    {
        $attendu = ['name' => 'ELM Admin', 'short_name' => 'ELM Admin'];

        $this->assertSame($attendu, $this->chargerConfigPwa('ELM Admin', null));
        $this->assertSame($attendu, $this->chargerConfigPwa('ELM Admin', ''));
    }

    public function test_une_configuration_absente_conserve_un_manifest_valide(): void
    {
        $this->assertSame(['name' => 'ELM', 'short_name' => 'ELM'], $this->chargerConfigPwa(null, null));
    }

    public function test_la_page_expose_le_nom_iphone_et_le_nom_de_la_carte_d_installation(): void
    {
        $this->withoutVite();
        config(['pwa.name' => 'ELM Admin Test', 'pwa.short_name' => 'ELM Admin T']);

        $this->get('/login')
            ->assertOk()
            ->assertSee('<meta name="apple-mobile-web-app-title" content="ELM Admin T">', false)
            ->assertInertia(fn (Assert $page) => $page->where('pwaName', 'ELM Admin Test'));
    }

    public function test_aucun_fichier_statique_ni_cache_ne_court_circuite_le_manifest(): void
    {
        // Apache sert un fichier existant avant Laravel : un public/manifest.json figerait le nom.
        $this->assertFileDoesNotExist(public_path('manifest.json'));

        $this->assertStringNotContainsString("'/manifest.json'", file_get_contents(public_path('sw.js')));
    }
}
