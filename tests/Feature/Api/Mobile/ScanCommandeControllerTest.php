<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Chantier du 31/08/2026 : le scan mobile (GET /v1/mobile/livraisons/scan/{reference}) doit
 * reconnaître à la fois les anciennes références (CMD-.../TR-...) et les nouvelles (VTE-/DST-/
 * TRF-) — cf. ScanCommandeController et docs/api-espace-client-contract.md.
 *
 * Correctif du 31/08/2026 (bloquant avant commit) : la numérotation étant désormais scopée par
 * organisation (cf. docs/references-metier.md), deux organisations peuvent porter EXACTEMENT la
 * même référence (ex: VTE-310826-001 pour l'une ET l'autre) — une recherche non filtrée par
 * organization_id retournerait alors potentiellement les données de la mauvaise organisation.
 * Les tests ci-dessous vérifient l'isolation stricte, y compris sur les préfixes legacy.
 */
class ScanCommandeControllerTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureClientRoles();
        $this->org = Organization::factory()->create();
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function actingAsClientRole(): void
    {
        $this->actingAsOrg($this->org);
    }

    private function actingAsOrg(Organization $org): void
    {
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('client');
        Sanctum::actingAs($user, ['*']);
    }

    private function scan(string $reference): TestResponse
    {
        return $this->getJson(route('client.livraisons.scan', ['reference' => $reference]));
    }

    // ── Commandes : legacy CMD- et nouveaux VTE-/DST- ────────────────────────

    public function test_scan_reconnait_lancien_prefixe_cmd(): void
    {
        CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'reference' => 'CMD-010125-001',
        ]);
        $this->actingAsClientRole();

        $this->scan('CMD-010125-001')
            ->assertOk()
            ->assertJson(['type' => 'commande', 'reference' => 'CMD-010125-001']);
    }

    public function test_scan_reconnait_le_nouveau_prefixe_vte(): void
    {
        CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'reference' => 'VTE-310826-001',
        ]);
        $this->actingAsClientRole();

        $this->scan('VTE-310826-001')
            ->assertOk()
            ->assertJson(['type' => 'commande', 'reference' => 'VTE-310826-001']);
    }

    public function test_scan_reconnait_le_nouveau_prefixe_dst(): void
    {
        CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'reference' => 'DST-310826-001',
        ]);
        $this->actingAsClientRole();

        $this->scan('DST-310826-001')
            ->assertOk()
            ->assertJson(['type' => 'commande', 'reference' => 'DST-310826-001']);
    }

    // ── Transferts : legacy TR- et nouveau TRF- ──────────────────────────────

    public function test_scan_reconnait_lancien_prefixe_tr(): void
    {
        $siteDest = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dest', 'type' => 'depot', 'localisation' => 'Kindia']);
        $creator = User::factory()->create(['organization_id' => $this->org->id]);
        TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->site->id,
            'site_destination_id' => $siteDest->id,
            'reference' => 'TR-00042-K7X',
            'created_by' => $creator->id,
        ]);
        $this->actingAsClientRole();

        $this->scan('TR-00042-K7X')
            ->assertOk()
            ->assertJson(['type' => 'transfert', 'reference' => 'TR-00042-K7X']);
    }

    public function test_scan_reconnait_le_nouveau_prefixe_trf(): void
    {
        $siteDest = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dest', 'type' => 'depot', 'localisation' => 'Kindia']);
        $creator = User::factory()->create(['organization_id' => $this->org->id]);
        TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->site->id,
            'site_destination_id' => $siteDest->id,
            'reference' => 'TRF-310826-001',
            'created_by' => $creator->id,
        ]);
        $this->actingAsClientRole();

        $this->scan('TRF-310826-001')
            ->assertOk()
            ->assertJson(['type' => 'transfert', 'reference' => 'TRF-310826-001']);
    }

    public function test_scan_rejette_un_prefixe_non_reconnu(): void
    {
        $this->actingAsClientRole();

        $this->scan('XYZ-000000-001')
            ->assertNotFound()
            ->assertJson(['message' => 'Référence non reconnue.']);
    }

    // ── Isolation multi-organisations : même référence, organisations distinctes ────

    /** @return array<string, array{0: string}> */
    public static function referencesCommandeProvider(): array
    {
        return [
            'legacy CMD' => ['CMD-310826-001'],
            'nouveau VTE' => ['VTE-310826-001'],
            'nouveau DST' => ['DST-310826-001'],
        ];
    }

    #[DataProvider('referencesCommandeProvider')]
    public function test_isolation_deux_organisations_avec_exactement_la_meme_reference_commande(string $reference): void
    {
        CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'reference' => $reference,
            'total_commande' => 11111,
        ]);

        $orgB = Organization::factory()->create();
        $siteB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'Kindia']);
        CommandeVente::factory()->create([
            'organization_id' => $orgB->id,
            'site_id' => $siteB->id,
            'reference' => $reference,
            'total_commande' => 22222,
        ]);

        $this->actingAsOrg($this->org);
        $this->scan($reference)
            ->assertOk()
            ->assertJson(['reference' => $reference, 'total' => 11111.0]);

        $this->actingAsOrg($orgB);
        $this->scan($reference)
            ->assertOk()
            ->assertJson(['reference' => $reference, 'total' => 22222.0]);
    }

    /** @return array<string, array{0: string}> */
    public static function referencesTransfertProvider(): array
    {
        return [
            'legacy TR' => ['TR-00042-K7X'],
            'nouveau TRF' => ['TRF-310826-001'],
        ];
    }

    #[DataProvider('referencesTransfertProvider')]
    public function test_isolation_deux_organisations_avec_exactement_la_meme_reference_transfert(string $reference): void
    {
        $siteDestA = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dest A', 'type' => 'depot', 'localisation' => 'Kindia']);
        $creatorA = User::factory()->create(['organization_id' => $this->org->id]);
        TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->site->id,
            'site_destination_id' => $siteDestA->id,
            'reference' => $reference,
            'created_by' => $creatorA->id,
        ]);

        $orgB = Organization::factory()->create();
        $siteSourceB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'Conakry']);
        $siteDestB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Dest B', 'type' => 'depot', 'localisation' => 'Kindia']);
        $creatorB = User::factory()->create(['organization_id' => $orgB->id]);
        TransfertLogistique::create([
            'organization_id' => $orgB->id,
            'site_source_id' => $siteSourceB->id,
            'site_destination_id' => $siteDestB->id,
            'reference' => $reference,
            'created_by' => $creatorB->id,
        ]);

        $this->actingAsOrg($this->org);
        $this->scan($reference)
            ->assertOk()
            ->assertJson(['reference' => $reference, 'site_source' => 'Site Test']);

        $this->actingAsOrg($orgB);
        $this->scan($reference)
            ->assertOk()
            ->assertJson(['reference' => $reference, 'site_source' => 'Site B']);
    }

    // ── Référence existant uniquement dans une autre organisation → 404 ─────────────

    public function test_scan_dune_commande_dune_autre_organisation_retourne_404(): void
    {
        $orgB = Organization::factory()->create();
        $siteB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'Kindia']);
        CommandeVente::factory()->create([
            'organization_id' => $orgB->id,
            'site_id' => $siteB->id,
            'reference' => 'VTE-310826-009',
        ]);

        // Utilisateur de $this->org (org A) — la référence n'existe que dans orgB.
        $this->actingAsClientRole();

        $this->scan('VTE-310826-009')
            ->assertNotFound()
            ->assertJson(['message' => 'Commande introuvable.']);
    }

    public function test_scan_dun_transfert_dune_autre_organisation_retourne_404(): void
    {
        $orgB = Organization::factory()->create();
        $siteSourceB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Src B', 'type' => 'depot', 'localisation' => 'Conakry']);
        $siteDestB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Dest B', 'type' => 'depot', 'localisation' => 'Kindia']);
        $creatorB = User::factory()->create(['organization_id' => $orgB->id]);
        TransfertLogistique::create([
            'organization_id' => $orgB->id,
            'site_source_id' => $siteSourceB->id,
            'site_destination_id' => $siteDestB->id,
            'reference' => 'TRF-310826-009',
            'created_by' => $creatorB->id,
        ]);

        // Utilisateur de $this->org (org A) — la référence n'existe que dans orgB.
        $this->actingAsClientRole();

        $this->scan('TRF-310826-009')
            ->assertNotFound()
            ->assertJson(['message' => 'Transfert introuvable.']);
    }
}
