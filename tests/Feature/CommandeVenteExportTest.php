<?php

namespace Tests\Feature;

use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Bouton "Exporter" de Ventes/Index.vue (ExportCommandeVenteController / VenteListExport) —
 * même contrôleur que ventes.export et distributions.export, cf. sa docblock.
 */
class CommandeVenteExportTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.exporter']);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeCommande(array $overrides = []): CommandeVente
    {
        static $seq = 0;
        $seq++;

        return CommandeVente::create(array_merge([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'statut' => StatutCommandeVente::LIVREE,
            'total_commande' => 5000,
            'reference' => 'TST-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT).'-'.uniqid(),
            'numero' => $seq,
        ], $overrides));
    }

    /** @return array<int, array<int, string>> */
    private function readSheet(TestResponse $response, string $sheetName): array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'export_ventes_test').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $spreadsheet = IOFactory::load($tmpPath);
        $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();
        $tableau = $sheet->toArray(null, true, true, false);
        @unlink($tmpPath);

        return $tableau;
    }

    // ── Permissions ──────────────────────────────────────────────────────────

    public function test_export_forbidden_sans_permission_ventes_exporter(): void
    {
        $readOnly = $this->makeUserWithPermissions($this->org, ['ventes.read']);

        $this->actingAs($readOnly)
            ->get(route('ventes.export'))
            ->assertForbidden();
    }

    public function test_export_forbidden_sans_authentification(): void
    {
        $this->get(route('ventes.export'))
            ->assertRedirect(route('login'));
    }

    // ── Contenu ──────────────────────────────────────────────────────────────

    public function test_export_retourne_un_xlsx_avec_la_commande_de_lorganisation(): void
    {
        $commande = $this->makeCommande();

        $response = $this->actingAs($this->user)->get(route('ventes.export'));
        $response->assertOk();

        $tableau = $this->readSheet($response, 'ventes');
        $entetes = $tableau[0];
        $this->assertContains('Référence', $entetes);
        $this->assertContains('Statut', $entetes);

        $indexRef = array_search('Référence', $entetes, true);
        $references = array_column(array_slice($tableau, 1), $indexRef);
        $this->assertContains($commande->reference, $references);
    }

    public function test_export_exclut_les_commandes_dune_autre_organisation(): void
    {
        $this->makeCommande();
        $otherOrg = Organization::factory()->create();
        $otherSite = Site::create([
            'organization_id' => $otherOrg->id,
            'nom' => 'Autre Org Site',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);
        $autreCommande = CommandeVente::create([
            'organization_id' => $otherOrg->id,
            'site_id' => $otherSite->id,
            'statut' => StatutCommandeVente::LIVREE,
            'total_commande' => 1000,
            'reference' => 'AUTRE-ORG-0001',
            'numero' => 1,
        ]);

        $response = $this->actingAs($this->user)->get(route('ventes.export'));
        $tableau = $this->readSheet($response, 'ventes');
        $entetes = $tableau[0];
        $indexRef = array_search('Référence', $entetes, true);
        $references = array_column(array_slice($tableau, 1), $indexRef);

        $this->assertNotContains($autreCommande->reference, $references);
    }

    public function test_export_respects_statut_filter(): void
    {
        $livree = $this->makeCommande(['statut' => StatutCommandeVente::LIVREE]);
        $this->makeCommande(['statut' => StatutCommandeVente::BROUILLON]);

        $response = $this->actingAs($this->user)
            ->get(route('ventes.export', ['statuts' => ['livree']]));
        $tableau = $this->readSheet($response, 'ventes');
        $entetes = $tableau[0];
        $indexRef = array_search('Référence', $entetes, true);
        $references = array_column(array_slice($tableau, 1), $indexRef);

        $this->assertSame([$livree->reference], $references);
    }

    public function test_export_respects_columns_selection(): void
    {
        $this->makeCommande();

        $response = $this->actingAs($this->user)
            ->get(route('ventes.export', ['columns' => ['reference', 'statut']]));
        $tableau = $this->readSheet($response, 'ventes');

        $this->assertSame(['Référence', 'Statut'], $tableau[0]);
    }

    public function test_export_csv_format(): void
    {
        $commande = $this->makeCommande();

        $response = $this->actingAs($this->user)
            ->get(route('ventes.export', ['format' => 'csv']));
        $response->assertOk();

        $content = $response->streamedContent();
        $this->assertStringContainsString($commande->reference, $content);
    }

    // $this->user (HasOrgAndUser::initOrgAndUser) est toujours admin_entreprise — cf. son
    // authorize() : un admin qui envoie site_ids restreint bien l'export à ce site.
    public function test_export_admin_peut_restreindre_a_un_site_via_site_ids(): void
    {
        $commandeIci = $this->makeCommande();
        $autreSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre Agence',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);
        $commandeAilleurs = $this->makeCommande(['site_id' => $autreSite->id]);

        $response = $this->actingAs($this->user)
            ->get(route('ventes.export', ['site_ids' => [$autreSite->id]]));
        $tableau = $this->readSheet($response, 'ventes');
        $entetes = $tableau[0];
        $indexRef = array_search('Référence', $entetes, true);
        $references = array_column(array_slice($tableau, 1), $indexRef);

        $this->assertContains($commandeAilleurs->reference, $references);
        $this->assertNotContains($commandeIci->reference, $references);
    }

    // Un non-admin (ni super_admin ni admin_entreprise) reste borné à ses propres sites même
    // s'il envoie un site_ids pointant ailleurs — même règle de périmètre que ventes.index (cf.
    // ExportCommandeVenteController).
    public function test_export_non_admin_reste_limite_a_son_site_meme_si_un_autre_site_id_est_envoye(): void
    {
        $commandeIci = $this->makeCommande();
        $autreSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre Agence',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);
        $commandeAilleurs = $this->makeCommande(['site_id' => $autreSite->id]);

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['organization_id' => $this->org->id]);
        $manager->assignRole('manager');
        $manager->givePermissionTo(['ventes.read', 'ventes.exporter']);
        $manager->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $response = $this->actingAs($manager)
            ->get(route('ventes.export', ['site_ids' => [$autreSite->id]]));
        $tableau = $this->readSheet($response, 'ventes');
        $entetes = $tableau[0];
        $indexRef = array_search('Référence', $entetes, true);
        $references = array_column(array_slice($tableau, 1), $indexRef);

        $this->assertContains($commandeIci->reference, $references);
        $this->assertNotContains($commandeAilleurs->reference, $references);
    }

    // ── distributions.export (même contrôleur, filtré par nom de route) ───────

    public function test_distributions_export_ninclut_que_les_distributions(): void
    {
        $vente = $this->makeCommande(['nature_operation' => NatureOperation::VENTE_STANDARD]);
        $distribution = $this->makeCommande(['nature_operation' => NatureOperation::DISTRIBUTION_CLIENT]);

        $response = $this->actingAs($this->user)->get(route('distributions.export'));
        $tableau = $this->readSheet($response, 'distributions');
        $entetes = $tableau[0];
        $indexRef = array_search('Référence', $entetes, true);
        $references = array_column(array_slice($tableau, 1), $indexRef);

        $this->assertContains($distribution->reference, $references);
        $this->assertNotContains($vente->reference, $references);
    }
}
