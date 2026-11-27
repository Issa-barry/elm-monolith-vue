<?php

namespace Tests\Feature;

use App\Enums\StatutTransfert;
use App\Features\ModuleFeature;
use App\Models\CommandeVenteLigne;
use App\Models\CommandeVenteRetourLigne;
use App\Models\MouvementStock;
use App\Models\Organization;
use App\Models\ProduitSeuilAlerte;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\VarianteStock;
use Database\Seeders\ProduitTypeDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Tableau de bord du stock, Stat-Stock (docs/tableau-de-bord-stock.md) : état actuel par article
 * (jamais de total entre articles), alertes (compteur : disponibilité + alerte ; tableau :
 * disponibilité seule), flux de la période hors paires annulées, transit, écarts de réception,
 * périmètre de la page Stock.
 */
class DashboardStockTest extends TestCase
{
    use HasProduitVariante;
    use RefreshDatabase;

    private Organization $org;

    private Site $matoto;

    private Site $cba;

    private User $admin;

    private ProduitVariante $alpha;

    private ProduitVariante $beta;

    protected function setUp(): void
    {
        parent::setUp();
        // Dimanche 22 novembre 2026 : « Ce mois » = du 1er au 22 novembre.
        Carbon::setTestNow('2026-11-22 10:00:00');

        $this->org = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($this->org->id);
        $this->matoto = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Matoto']);
        $this->cba = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Cba']);

        Permission::firstOrCreate(['name' => 'produits.read', 'guard_name' => 'web']);
        $this->admin = $this->utilisateur('admin_entreprise', [$this->matoto]);

        $this->alpha = $this->makeProduitAvecVariante($this->org, ['nom' => 'Alpha pack'], ['sku' => 'ALPHA'])->variantePrincipale()->first();
        $this->beta = $this->makeProduitAvecVariante($this->org, ['nom' => 'Beta pack'], ['sku' => 'BETA'])->variantePrincipale()->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function utilisateur(string $role, array $sites, array $permissions = ['produits.read']): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }
        foreach ($sites as $i => $site) {
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => $i === 0]);
        }

        return $user;
    }

    private function stock(ProduitVariante $variante, Site $site, int $physique, int $reservee = 0): void
    {
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $variante->id,
            'site_id' => $site->id,
            'qte_stock' => $physique,
            'qte_reservee' => $reservee,
        ]);
    }

    private function mouvement(ProduitVariante $variante, Site $site, string $type, int $quantite, string $date, ?string $source = null, ?string $notes = null): MouvementStock
    {
        return MouvementStock::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'produit_variante_id' => $variante->id,
            'type' => $type,
            'quantite' => $quantite,
            'date' => $date,
            'stock_avant' => 0,
            'stock_apres' => 0,
            'source_type' => $source,
            'source_id' => $source ? (string) Str::ulid() : null,
            'notes' => $notes,
            'created_by' => $this->admin->id,
        ]);
    }

    private function transfert(Site $destination, StatutTransfert $statut, int $chargee, ?int $recue = null, ?string $arrivee = null, int $retournee = 0): void
    {
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => ($destination->is($this->matoto) ? $this->cba : $this->matoto)->id,
            'site_destination_id' => $destination->id,
            'statut' => $statut,
            'date_arrivee_reelle' => $arrivee,
            'created_by' => $this->admin->id,
        ]);
        TransfertLigne::create([
            'transfert_logistique_id' => $transfert->id,
            'variante_id' => $this->alpha->id,
            'quantite_demandee' => $chargee,
            'quantite_chargee' => $chargee,
            'quantite_recue' => $recue,
            'quantite_retournee' => $retournee,
        ]);
    }

    private function page(User $user, array $parametres = []): array
    {
        $reponse = $this->actingAs($user)->get(route('dashboard.stock', $parametres));
        $reponse->assertOk()->assertSessionHasNoErrors();

        return $reponse->viewData('page')['props'];
    }

    public function test_l_etat_porte_sur_l_article_choisi_et_la_synthese_ne_totalise_jamais_les_articles(): void
    {
        $this->stock($this->alpha, $this->matoto, 100, 30);
        $this->stock($this->alpha, $this->cba, 50);
        $this->stock($this->beta, $this->matoto, 10);

        $props = $this->page($this->admin);

        // Premier article de la page Stock (ordre des produits) par défaut.
        $this->assertSame($this->alpha->id, $props['article']);
        $this->assertSame(['Alpha pack', 'Beta pack'], array_column($props['articles'], 'label'));
        $this->assertSame(['physique' => 150, 'reservee' => 30, 'disponible' => 120, 'en_transit' => 0, 'transferts_en_transit' => 0], $props['etat']);
        // Stock par site de l'article, du plus gros au plus petit.
        $this->assertSame(['Matoto', 'Cba'], array_column($props['sites'], 'label'));
        $this->assertSame([70, 50], array_column($props['sites'], 'disponible'));

        $this->assertSame(['Alpha pack', 'Beta pack'], array_column($props['synthese'], 'label'));
        $this->assertSame([150, 10], array_column($props['synthese'], 'physique'));
        $this->assertArrayNotHasKey('total', $props['synthese']);

        $beta = $this->page($this->admin, ['article' => $this->beta->id]);
        $this->assertSame(10, $beta['etat']['physique']);
    }

    public function test_un_article_ou_une_agence_hors_perimetre_est_refuse(): void
    {
        $autreOrg = Organization::factory()->create();
        ProduitTypeDefaultSeeder::seedPourOrganisation($autreOrg->id);
        $etranger = $this->makeProduitAvecVariante($autreOrg, ['nom' => 'Ailleurs'])->variantePrincipale()->first();

        $this->actingAs($this->admin)->get(route('dashboard.stock', ['article' => $etranger->id]))
            ->assertSessionHasErrors(['article' => 'Article inconnu ou hors de votre périmètre.']);

        $responsable = $this->utilisateur('manager', [$this->matoto]);
        $this->actingAs($responsable)->get(route('dashboard.stock', ['site_id' => $this->cba->id]))
            ->assertSessionHasErrors(['site_id' => 'Agence inconnue ou hors de votre périmètre.']);
    }

    public function test_le_compteur_d_alertes_suit_la_regle_des_badges_et_le_tableau_celle_des_ecrans(): void
    {
        // Alpha / Matoto : alerte active, seuil 50, disponible 20 → stock faible, compté.
        ProduitSeuilAlerte::create(['organization_id' => $this->org->id, 'produit_id' => $this->alpha->produit_id, 'site_id' => $this->matoto->id, 'actif' => true, 'seuil_alerte_stock' => 50]);
        $this->stock($this->alpha, $this->matoto, 20);
        // Alpha / Cba : disponible, sans alerte, 0 → rupture affichée mais non comptée.
        // Beta / Cba : non disponible sur le site → ni compté ni affiché.
        ProduitSeuilAlerte::create(['organization_id' => $this->org->id, 'produit_id' => $this->beta->produit_id, 'site_id' => $this->cba->id, 'disponible' => false, 'actif' => false]);
        // Beta / Matoto : 100 au-dessus du seuil global (10) → disponible.
        $this->stock($this->beta, $this->matoto, 100);

        $props = $this->page($this->admin);

        $this->assertSame(['total' => 1, 'ruptures' => 0, 'faibles' => 1, 'negatifs' => 0], $props['alertes']);
        $lignes = collect($props['alertes_lignes']);
        $this->assertSame([['Alpha pack', 'Cba', 'rupture', false], ['Alpha pack', 'Matoto', 'stock_faible', true]],
            $lignes->map(fn ($l) => [$l['article'], $l['site'], $l['statut'], $l['alerte_active']])->all());
    }

    public function test_les_flux_de_la_periode_excluent_les_paires_annulees_et_suivent_la_date_metier(): void
    {
        $this->mouvement($this->alpha, $this->matoto, 'entree', 50, '2026-11-01', notes: 'Après production');
        $this->mouvement($this->alpha, $this->matoto, 'entree', 5, '2026-11-05', CommandeVenteRetourLigne::class);
        $this->mouvement($this->alpha, $this->cba, 'sortie', 30, '2026-11-10', CommandeVenteLigne::class);
        $this->mouvement($this->alpha, $this->matoto, 'sortie', 4, '2026-11-12', notes: 'Perte');
        $this->mouvement($this->alpha, $this->matoto, 'sortie', 2, '2026-11-22', notes: 'Casse');
        // Hors période.
        $this->mouvement($this->alpha, $this->matoto, 'sortie', 99, '2026-10-31', CommandeVenteLigne::class);
        // Paire annulée : la sortie et son contre-mouvement ne comptent ni l'un ni l'autre.
        $annulee = $this->mouvement($this->alpha, $this->matoto, 'sortie', 7, '2026-11-15', CommandeVenteLigne::class);
        $contre = $this->mouvement($this->alpha, $this->matoto, 'entree', 7, '2026-11-16', CommandeVenteLigne::class, "Contre-mouvement — annule le mouvement {$annulee->id}");
        $annulee->update(['annule_par_id' => $contre->id]);
        // Autre article : jamais mêlé à Alpha.
        $this->mouvement($this->beta, $this->matoto, 'sortie', 1000, '2026-11-10', CommandeVenteLigne::class);

        $props = $this->page($this->admin);

        $this->assertSame(['entrees' => 55, 'sorties' => 36, 'pertes_casses' => 6, 'ecarts_manquants' => 0, 'ecarts_surplus' => 0], $props['flux_periode']);
        $this->assertSame(1000, collect($props['synthese'])->firstWhere('label', 'Beta pack')['sorties']);

        $series = collect($props['evolution']['series'])->keyBy(fn ($s) => $s['sens'].'|'.$s['motif']);
        $this->assertSame('jour', $props['evolution']['granularite']);
        $this->assertCount(22, $props['evolution']['tranches']);
        $this->assertSame(['entree|apres_production', 'entree|retour_livraison', 'sortie|casse', 'sortie|perte', 'sortie|vente'], $series->keys()->all());
        $this->assertSame('Après production', $series['entree|apres_production']['label']);
        $this->assertSame(-30, array_sum($series['sortie|vente']['valeurs']));
        $this->assertSame(50, $series['entree|apres_production']['valeurs'][0]);

        // Dates libres : bornes incluses.
        $libres = $this->page($this->admin, ['date_debut' => '2026-11-05', 'date_fin' => '2026-11-12']);
        $this->assertSame(['entrees' => 5, 'sorties' => 34, 'pertes_casses' => 4, 'ecarts_manquants' => 0, 'ecarts_surplus' => 0], $libres['flux_periode']);
    }

    public function test_le_transit_et_les_ecarts_de_reception_portent_sur_les_transferts_vers_les_sites(): void
    {
        $this->transfert($this->matoto, StatutTransfert::TRANSIT, 40);
        $this->transfert($this->cba, StatutTransfert::RECEPTION, 20, 17, '2026-11-15');
        $this->transfert($this->cba, StatutTransfert::CLOTURE, 10, 12, '2026-11-16');
        // Exclus : annulé, reçu hors période.
        $this->transfert($this->matoto, StatutTransfert::ANNULE, 500, 0, '2026-11-15');
        $this->transfert($this->cba, StatutTransfert::RECEPTION, 30, 1, '2026-10-20');

        $props = $this->page($this->admin);

        $this->assertSame(40, $props['etat']['en_transit']);
        $this->assertSame(1, $props['etat']['transferts_en_transit']);
        $this->assertSame(3, $props['flux_periode']['ecarts_manquants']);
        $this->assertSame(2, $props['flux_periode']['ecarts_surplus']);
    }

    public function test_un_responsable_ne_voit_que_ses_agences(): void
    {
        $this->stock($this->alpha, $this->matoto, 10);
        $this->stock($this->alpha, $this->cba, 500);
        $this->mouvement($this->alpha, $this->matoto, 'sortie', 3, '2026-11-10', CommandeVenteLigne::class);
        $this->mouvement($this->alpha, $this->cba, 'sortie', 300, '2026-11-10', CommandeVenteLigne::class);
        $this->transfert($this->cba, StatutTransfert::TRANSIT, 80);
        $responsable = $this->utilisateur('manager', [$this->matoto]);

        $props = $this->page($responsable);

        $this->assertTrue($props['perimetre_limite']);
        $this->assertSame(['Matoto'], array_column($props['agences'], 'label'));
        $this->assertSame(10, $props['etat']['physique']);
        $this->assertSame(0, $props['etat']['en_transit']);
        $this->assertSame(3, $props['flux_periode']['sorties']);
        $this->assertSame(['Matoto'], array_column($props['sites'], 'label'));

        // Filtre agence de l'administrateur : toute la page suit l'agence choisie.
        $cba = $this->page($this->admin, ['site_id' => $this->cba->id]);
        $this->assertSame(500, $cba['etat']['physique']);
        $this->assertSame(300, $cba['flux_periode']['sorties']);
        $this->assertSame(80, $cba['etat']['en_transit']);
    }

    public function test_l_acces_exige_la_lecture_des_produits_et_le_module(): void
    {
        $this->actingAs($this->utilisateur('manager', [$this->matoto], []))
            ->get(route('dashboard.stock'))
            ->assertForbidden();

        Feature::for($this->org)->deactivate(ModuleFeature::PRODUITS);
        $this->actingAs($this->admin)->get(route('dashboard.stock'))->assertForbidden();
    }

    public function test_le_journal_est_lu_en_une_seule_requete_pour_les_flux_et_le_graphique(): void
    {
        foreach (range(1, 40) as $n) {
            $this->mouvement($n % 2 ? $this->alpha : $this->beta, $n % 3 ? $this->matoto : $this->cba, $n % 2 ? 'entree' : 'sortie', $n, '2026-11-'.str_pad((string) (($n % 20) + 1), 2, '0', STR_PAD_LEFT), CommandeVenteLigne::class);
        }
        // Saisi le 22 novembre, daté du 15 octobre : hors de « Ce mois », compté dans le mois d'octobre.
        $this->mouvement($this->alpha, $this->matoto, 'entree', 7, '2026-10-15', null, 'Après production');

        DB::enableQueryLog();
        $props = $this->page($this->admin, ['article' => $this->alpha->id]);
        $requetes = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, '"mouvements_stock" as "m"'));
        DB::disableQueryLog();

        $this->assertCount(1, $requetes, $requetes->implode(PHP_EOL));
        // Entrées d'Alpha (n impairs) : 1 + 3 + … + 39 = 400, toutes en novembre.
        $this->assertSame(400, $props['flux_periode']['entrees']);
        $this->assertSame(400, array_sum(array_merge(...array_column(array_filter($props['evolution']['series'], fn ($s) => $s['sens'] === 'entree'), 'valeurs'))));

        // « Toutes les périodes » : tranches mensuelles tirées de la même lecture.
        $tout = $this->page($this->admin, ['article' => $this->alpha->id, 'periode' => 'tout']);
        $this->assertSame('mois', $tout['evolution']['granularite']);
        $this->assertSame(['2026-10', '2026-11'], array_column($tout['evolution']['tranches'], 'cle'));
        $this->assertSame(407, $tout['flux_periode']['entrees']);
        $production = collect($tout['evolution']['series'])->firstWhere('motif', 'apres_production');
        $this->assertSame([7, 0], $production['valeurs']);
    }

    public function test_sans_stock_ni_mouvement_la_page_reste_coherente(): void
    {
        $props = $this->page($this->admin);

        $this->assertSame(['physique' => 0, 'reservee' => 0, 'disponible' => 0, 'en_transit' => 0, 'transferts_en_transit' => 0], $props['etat']);
        $this->assertSame([], $props['evolution']['series']);
        $this->assertCount(22, $props['evolution']['tranches']);
        // Sans stock, chaque couple disponible est en rupture : affiché, jamais compté sans alerte.
        $this->assertSame(0, $props['alertes']['total']);
        $this->assertCount(4, $props['alertes_lignes']);
    }
}
