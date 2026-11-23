<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Enums\ModeRemiseGrossiste;
use App\Enums\NatureOperation;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Support\Periodes\Periode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertStatus(200);
    }

    // ── Périmètre d'agence (SiteScopeService, cf. docs/rapports.md) ──────────

    private function facture(Organization $org, Site $site, float $montant, string $statut = 'impayee'): FactureVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'total_commande' => $montant,
        ]);

        return FactureVente::create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => $statut,
        ]);
    }

    /** @return array{0: Organization, 1: Site, 2: Site} */
    private function deuxAgences(): array
    {
        $org = Organization::factory()->create();
        $matoto = Site::create(['organization_id' => $org->id, 'nom' => 'Matoto', 'type' => 'agence', 'localisation' => 'Conakry']);
        $kouria = Site::create(['organization_id' => $org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $this->facture($org, $matoto, 100_000);
        $this->facture($org, $kouria, 900_000, 'payee');

        return [$org, $matoto, $kouria];
    }

    /** @return array<string, mixed> */
    private function props(User $user): array
    {
        return $this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('page')['props'];
    }

    public function test_un_utilisateur_non_admin_ne_voit_que_les_chiffres_de_ses_agences(): void
    {
        [$org, $matoto] = $this->deuxAgences();
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['organization_id' => $org->id]);
        $manager->assignRole('manager');
        $manager->sites()->attach($matoto->id, ['role' => 'employe', 'is_default' => true]);

        $props = $this->props($manager);

        $this->assertSame(1, $props['stats_factures']['total_count']);
        $this->assertEquals(100_000, $props['stats_factures']['total_montant']);
        $this->assertEquals(0, $props['stats_factures']['payees_montant']);
        $this->assertSame(['Matoto'], array_column($props['ca_par_site'], 'nom'));
        $this->assertEquals(100_000, array_sum(array_column($props['evolution']['tranches'], 'impayees')));
        $this->assertEquals(0, array_sum(array_column($props['evolution']['tranches'], 'payees')));
        $this->assertSame(['Matoto'], $props['agences']);
    }

    public function test_un_administrateur_voit_toute_l_organisation(): void
    {
        [$org, $matoto] = $this->deuxAgences();
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $admin = User::factory()->create(['organization_id' => $org->id]);
        $admin->assignRole('admin_entreprise');
        $admin->sites()->attach($matoto->id, ['role' => 'employe', 'is_default' => true]);

        $props = $this->props($admin);

        $this->assertSame(2, $props['stats_factures']['total_count']);
        $this->assertEquals(1_000_000, $props['stats_factures']['total_montant']);
        $this->assertEqualsCanonicalizing(['Matoto', 'Kouria'], array_column($props['ca_par_site'], 'nom'));
        $this->assertNull($props['agences']);
    }

    // ── Ventes par processus (identité résolue comme la liste des ventes) ────

    private function factureProcessus(Organization $org, Site $site, float $montant, array $commande, ?ClientType $clientType = null, string $statut = 'impayee'): void
    {
        $client = $clientType
            ? Client::factory()->create(['organization_id' => $org->id, 'type' => $clientType->value])
            : null;

        $cmd = CommandeVente::factory()->create(array_merge([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'client_id' => $client?->id,
            'total_commande' => $montant,
        ], $commande));

        FactureVente::create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'commande_vente_id' => $cmd->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => $statut,
        ]);
    }

    public function test_le_ca_est_reparti_par_processus_de_vente_hors_factures_annulees(): void
    {
        $org = Organization::factory()->create();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Matoto', 'type' => 'agence', 'localisation' => 'Conakry']);

        $this->factureProcessus($org, $site, 100_000, ['nature_operation' => NatureOperation::VENTE_STANDARD->value], ClientType::REVENDEUR);
        $this->factureProcessus($org, $site, 50_000, ['nature_operation' => NatureOperation::VENTE_STANDARD->value]);
        // Grossiste en enlèvement : reste une Vente (décision produit du 05/09/2026).
        $this->factureProcessus($org, $site, 30_000, ['mode_remise_grossiste' => ModeRemiseGrossiste::ENLEVEMENT->value], ClientType::GROSSISTE);
        $this->factureProcessus($org, $site, 400_000, ['mode_remise_grossiste' => ModeRemiseGrossiste::LIVRAISON->value], ClientType::GROSSISTE);
        $this->factureProcessus($org, $site, 200_000, ['nature_operation' => NatureOperation::DISTRIBUTION_CLIENT->value], ClientType::REVENDEUR);
        $this->factureProcessus($org, $site, 999_000, ['nature_operation' => NatureOperation::DISTRIBUTION_CLIENT->value], ClientType::REVENDEUR, 'annulee');

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $admin = User::factory()->create(['organization_id' => $org->id]);
        $admin->assignRole('admin_entreprise');
        $admin->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $parCode = collect($this->props($admin)['ca_par_processus'])->keyBy('code');

        $this->assertSame(['vente', 'distribution_client', 'transfert_grossiste'], $parCode->keys()->all());
        $this->assertEquals(180_000, $parCode['vente']['montant']);
        $this->assertSame(3, $parCode['vente']['nb_factures']);
        $this->assertSame('Vente', $parCode['vente']['label']);
        $this->assertEquals(200_000, $parCode['distribution_client']['montant']);
        $this->assertSame(1, $parCode['distribution_client']['nb_factures']);
        $this->assertEquals(400_000, $parCode['transfert_grossiste']['montant']);
        $this->assertSame('Transfert grossiste', $parCode['transfert_grossiste']['label']);
    }

    public function test_le_ca_par_processus_respecte_le_perimetre_d_agence(): void
    {
        [$org, $matoto] = $this->deuxAgences();
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager = User::factory()->create(['organization_id' => $org->id]);
        $manager->assignRole('manager');
        $manager->sites()->attach($matoto->id, ['role' => 'employe', 'is_default' => true]);

        $parCode = collect($this->props($manager)['ca_par_processus'])->keyBy('code');

        $this->assertEquals(100_000, $parCode['vente']['montant']);
        $this->assertEquals(100_000, $parCode->sum('montant'));
    }

    // ── Filtre de période unique (périodes prédéfinies et dates libres) ──────

    private function adminAvecFactures(): User
    {
        Carbon::setTestNow('2026-11-23 10:00:00');
        $org = Organization::factory()->create();
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Matoto', 'type' => 'agence', 'localisation' => 'Conakry']);

        foreach ([
            ['2026-10-31 23:59:59', 7_000, 'payee'],      // veille du début : exclue
            ['2026-11-01 00:00:00', 100_000, 'payee'],    // début inclus
            ['2026-11-03 12:00:00', 40_000, 'partiel'],
            ['2026-11-04 23:59:59', 60_000, 'impayee'],   // fin incluse (toute la journée)
            ['2026-11-04 08:00:00', 500_000, 'annulee'],  // annulée : hors CA
            ['2026-11-05 00:00:00', 9_000_000, 'payee'],  // lendemain de la fin : exclue
            ['2026-08-15 09:00:00', 300_000, 'impayee'],
        ] as [$date, $montant, $statut]) {
            $facture = $this->facture($org, $site, $montant, $statut);
            DB::table('factures_ventes')->where('id', $facture->id)->update(['created_at' => $date]);
        }

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $admin = User::factory()->create(['organization_id' => $org->id]);
        $admin->assignRole('admin_entreprise');
        $admin->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $admin;
    }

    /**
     * Cartes, graphique et répartitions portent sur la même période : le graphique redonne le
     * montant des factures payées et le CA hors annulées des répartitions.
     *
     * @param  array<string, mixed>  $props
     */
    private function assertCoherent(array $props): void
    {
        $tranches = $props['evolution']['tranches'];
        $this->assertEqualsWithDelta($props['stats_factures']['payees_montant'], array_sum(array_column($tranches, 'payees')), 0.01);
        $caGraphique = array_sum(array_column($tranches, 'payees'))
            + array_sum(array_column($tranches, 'partielles'))
            + array_sum(array_column($tranches, 'impayees'));
        $this->assertEqualsWithDelta($caGraphique, array_sum(array_column($props['ca_par_site'], 'montant')), 0.01);
        $this->assertEqualsWithDelta($caGraphique, array_sum(array_column($props['ca_par_processus'], 'montant')), 0.01);
    }

    public function test_une_periode_personnalisee_pilote_toute_la_page_bornes_incluses(): void
    {
        $admin = $this->adminAvecFactures();

        $props = $this->actingAs($admin)
            ->get(route('dashboard', ['date_debut' => '2026-11-01', 'date_fin' => '2026-11-04']))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame('personnalisee', $props['periode']['cle']);
        $this->assertSame('Du 01/11/2026 au 04/11/2026', $props['periode']['libelle']);
        $this->assertSame(4, $props['stats_factures']['total_count']);
        $this->assertEquals(100_000, $props['stats_factures']['payees_montant']);
        $this->assertSame('jour', $props['evolution']['granularite']);
        $this->assertSame(['01/11', '02/11', '03/11', '04/11'], array_column($props['evolution']['tranches'], 'label'));
        $this->assertEquals([0, 0, 0, 60_000], array_column($props['evolution']['tranches'], 'impayees'));
        $this->assertEquals(200_000, array_sum(array_column($props['ca_par_site'], 'montant')));
        $this->assertCoherent($props);

    }

    public function test_une_periode_de_plus_de_31_jours_est_mois_par_mois_et_toutes_les_periodes_depuis_la_premiere_vente(): void
    {
        $admin = $this->adminAvecFactures();

        $longue = $this->actingAs($admin)
            ->get(route('dashboard', ['date_debut' => '2026-08-01', 'date_fin' => '2026-11-30']))
            ->assertOk()->viewData('page')['props'];
        $this->assertSame('mois', $longue['evolution']['granularite']);
        $this->assertSame(['Août', 'Sep', 'Oct', 'Nov'], array_column($longue['evolution']['tranches'], 'label'));
        $this->assertCoherent($longue);

        $tout = $this->actingAs($admin)->get(route('dashboard', ['periode' => 'tout']))->assertOk()->viewData('page')['props'];
        $this->assertSame(['Août', 'Sep', 'Oct', 'Nov'], array_column($tout['evolution']['tranches'], 'label'));
        $this->assertCoherent($tout);

    }

    /** @return array<string, array{0: string}> */
    public static function periodesPredefinies(): array
    {
        $cles = array_values(array_diff(Periode::TABLEAU_DE_BORD, [Periode::PERSONNALISEE]));

        return array_combine($cles, array_map(fn (string $cle) => [$cle], $cles));
    }

    #[DataProvider('periodesPredefinies')]
    public function test_chaque_periode_predefinie_pilote_toute_la_page(string $cle): void
    {
        $admin = $this->adminAvecFactures();

        $props = $this->actingAs($admin)->get(route('dashboard', ['periode' => $cle]))->assertOk()->viewData('page')['props'];

        $this->assertSame($cle, $props['periode']['cle']);
        $this->assertCoherent($props);

    }

    public function test_une_periode_invalide_est_refusee_avec_un_message(): void
    {
        $admin = $this->adminAvecFactures();

        $this->actingAs($admin)->from(route('dashboard'))
            ->get(route('dashboard', ['date_debut' => '2026-11-04', 'date_fin' => '2026-11-01']))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors(['date_fin' => 'La date de fin doit être postérieure ou égale à la date de début.']);

        $this->actingAs($admin)
            ->get(route('dashboard', ['periode' => 'trimestre_dernier']))
            ->assertSessionHasErrors(['periode' => 'Période inconnue.']);

    }
}
