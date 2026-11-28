<?php

namespace Tests\Feature;

use App\Enums\CategorieDepense;
use App\Enums\StatutDepense;
use App\Features\ModuleFeature;
use App\Features\TableauDeBordFeature;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tableau de bord des dépenses, Stat-Dépenses (docs/tableau-de-bord-depenses.md) : six cartes et
 * leurs égalités (total = payées + en attente ; payées = charges + avances), avances selon
 * CATEGORIES_AVANCE_TIERS, rejetées d'après le statut actuel, période sur `date_depense` (bornes
 * incluses), filtre agence et périmètre DepensePerimetre, graphiques et nombre de requêtes.
 */
class DashboardDepensesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $matoto;

    private Site $cba;

    private User $auteur;

    /** @var array<string, DepenseType> */
    private array $types = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Dimanche 22 novembre 2026 : « Ce mois » = du 1er au 22 novembre.
        Carbon::setTestNow('2026-11-22 10:00:00');

        $this->org = Organization::factory()->create();
        // Désactivé par défaut (ADR 0071) : refus couvert par TableauxDeBordActivationTest.
        Feature::for($this->org)->activate(TableauDeBordFeature::DEPENSES);
        $this->matoto = $this->site('Matoto');
        $this->cba = $this->site('Cba');
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function site(string $nom, ?Organization $org = null): Site
    {
        return Site::create([
            'organization_id' => ($org ?? $this->org)->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function type(CategorieDepense $categorie, ?string $libelle = null): DepenseType
    {
        $libelle ??= $categorie->label();

        return $this->types[$libelle] ??= DepenseType::factory()->create([
            'organization_id' => $this->org->id,
            'libelle' => $libelle,
            'categorie' => $categorie->value,
        ]);
    }

    private function depense(
        int $montant,
        StatutDepense $statut,
        string $date,
        CategorieDepense $categorie = CategorieDepense::INTERNE,
        ?Site $site = null,
        ?User $auteur = null,
        ?string $libelleType = null,
    ): Depense {
        return Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => ($auteur ?? $this->auteur)->id,
            'depense_type_id' => $this->type($categorie, $libelleType)->id,
            'site_id' => ($site ?? $this->matoto)->id,
            'montant' => $montant,
            'date_depense' => $date,
            'statut' => $statut->value,
        ]);
    }

    private function staff(array $permissions, ?Site $site = null, string $role = 'manager'): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }

        $user->sites()->attach(($site ?? $this->matoto)->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function admin(): User
    {
        return $this->staff(['depenses.read'], $this->matoto, 'admin_entreprise');
    }

    private function page(User $user, array $parametres = []): array
    {
        $reponse = $this->actingAs($user)->get(route('dashboard.depenses', $parametres));
        $reponse->assertOk()->assertSessionHasNoErrors();

        return $reponse->viewData('page')['props'];
    }

    public function test_les_six_cartes_respectent_les_formules_et_les_egalites(): void
    {
        $this->depense(100_000, StatutDepense::VALIDE, '2026-11-05');
        $this->depense(40_000, StatutDepense::VALIDE, '2026-11-10', CategorieDepense::LIVREUR);
        // Option A : salarié, prestataire et client restent des charges de l'entreprise.
        $this->depense(30_000, StatutDepense::VALIDE, '2026-11-11', CategorieDepense::EMPLOYE);
        $this->depense(6_000, StatutDepense::VALIDE, '2026-11-12', CategorieDepense::PRESTATAIRE);
        $this->depense(4_000, StatutDepense::VALIDE, '2026-11-12', CategorieDepense::CLIENT);
        $this->depense(10_000, StatutDepense::VALIDE, '2026-11-13', CategorieDepense::VEHICULE);
        $this->depense(20_000, StatutDepense::SOUMIS, '2026-11-03');
        $this->depense(5_000, StatutDepense::SOUMIS, '2026-11-20');
        $this->depense(15_000, StatutDepense::REJETE, '2026-11-08');

        // Exclues : brouillon, annulée, supprimée, hors période, autre organisation.
        $this->depense(77_000, StatutDepense::BROUILLON, '2026-11-05');
        $this->depense(99_000, StatutDepense::ANNULE, '2026-11-05');
        $this->depense(1_000_000, StatutDepense::VALIDE, '2026-11-06')->delete();
        $this->depense(500_000, StatutDepense::VALIDE, '2026-10-31');
        $autreOrg = Organization::factory()->create();
        Depense::factory()->valide()->create([
            'organization_id' => $autreOrg->id,
            'site_id' => $this->site('Ailleurs', $autreOrg)->id,
            'montant' => 300_000,
            'date_depense' => '2026-11-05',
        ]);

        $props = $this->page($this->admin());
        $i = $props['indicateurs'];

        $this->assertEquals(190_000, $i['payees']['montant']);
        $this->assertEquals(140_000, $i['charges']['montant']);
        $this->assertEquals(50_000, $i['avances']['montant']);
        $this->assertEquals(215_000, $i['total']['montant']);
        $this->assertEquals(['montant' => 25_000, 'nombre' => 2, 'plus_ancienne' => '2026-11-03', 'anciennete_jours' => 19], $props['en_attente']);
        // Taux de rejet = 1 rejetée / (6 validées + 1 rejetée).
        $this->assertEquals(['montant' => 15_000, 'nombre' => 1, 'taux' => 14.3], $props['rejetees']);

        // Égalités : total = payées + en attente ; payées = charges + avances ; rejetées hors des deux.
        $this->assertEquals($i['total']['montant'], $i['payees']['montant'] + $props['en_attente']['montant']);
        $this->assertEquals($i['payees']['montant'], $i['charges']['montant'] + $i['avances']['montant']);
    }

    public function test_les_evolutions_comparent_la_meme_duree_de_la_periode_precedente(): void
    {
        $this->depense(100_000, StatutDepense::VALIDE, '2026-11-05');
        $this->depense(40_000, StatutDepense::VALIDE, '2026-11-10', CategorieDepense::LIVREUR);
        // Du 1er au 22 octobre : comparé à « Ce mois » au 22 novembre.
        $this->depense(50_000, StatutDepense::VALIDE, '2026-10-22');
        $this->depense(30_000, StatutDepense::SOUMIS, '2026-10-02');
        // Hors comparaison : après le 22 octobre.
        $this->depense(999_000, StatutDepense::VALIDE, '2026-10-23');

        $props = $this->page($this->admin());
        $i = $props['indicateurs'];

        $this->assertNotNull($props['libelle_comparaison']);
        $this->assertEquals(180.0, $i['payees']['evolution']); // 140 000 contre 50 000
        $this->assertEquals(100.0, $i['charges']['evolution']); // 100 000 contre 50 000
        $this->assertEquals(75.0, $i['total']['evolution']); // 140 000 contre 80 000
        $this->assertNull($i['avances']['evolution'], 'Rien sur la période précédente : pas de comparaison.');
    }

    public function test_toutes_les_periodes_n_ont_pas_de_comparaison(): void
    {
        $this->depense(100_000, StatutDepense::VALIDE, '2025-03-05');
        $this->depense(40_000, StatutDepense::VALIDE, '2026-11-10');

        $props = $this->page($this->admin(), ['periode' => 'tout']);

        $this->assertNull($props['libelle_comparaison']);
        $this->assertEquals(140_000, $props['indicateurs']['payees']['montant']);
        $this->assertNull($props['indicateurs']['payees']['evolution']);
        $this->assertSame('mois', $props['evolution']['granularite']);
    }

    public function test_les_dates_libres_incluent_leurs_deux_bornes(): void
    {
        $this->depense(1_000, StatutDepense::VALIDE, '2026-11-04');
        $this->depense(10_000, StatutDepense::VALIDE, '2026-11-05');
        $this->depense(20_000, StatutDepense::VALIDE, '2026-11-10');
        $this->depense(2_000, StatutDepense::VALIDE, '2026-11-11');

        $props = $this->page($this->admin(), ['date_debut' => '2026-11-05', 'date_fin' => '2026-11-10']);

        $this->assertEquals(30_000, $props['indicateurs']['payees']['montant']);
    }

    public function test_le_graphique_d_evolution_separe_charges_et_avances_par_jour(): void
    {
        $this->depense(100_000, StatutDepense::VALIDE, '2026-11-05');
        $this->depense(40_000, StatutDepense::VALIDE, '2026-11-05', CategorieDepense::PARRAIN);
        $this->depense(9_000, StatutDepense::SOUMIS, '2026-11-05');

        $props = $this->page($this->admin());
        $tranches = collect($props['evolution']['tranches'])->keyBy('cle');

        $this->assertSame('jour', $props['evolution']['granularite']);
        $this->assertCount(22, $tranches);
        $this->assertEquals(100_000, $tranches['2026-11-05']['charges']);
        $this->assertEquals(40_000, $tranches['2026-11-05']['avances']);
        $this->assertEquals(140_000, $tranches['2026-11-05']['payees']);
        $this->assertEquals(149_000, $tranches['2026-11-05']['total']);
        $this->assertEquals(0, $tranches['2026-11-06']['payees']);
        // Somme des tranches = cartes.
        $this->assertEquals($props['indicateurs']['charges']['montant'], $tranches->sum('charges'));
        $this->assertEquals($props['indicateurs']['avances']['montant'], $tranches->sum('avances'));
    }

    public function test_la_repartition_par_type_totalise_les_payees_et_regroupe_les_autres_types(): void
    {
        foreach (range(1, 9) as $n) {
            $this->depense($n * 1_000, StatutDepense::VALIDE, '2026-11-05', libelleType: "Type {$n}");
        }
        $this->depense(50_000, StatutDepense::SOUMIS, '2026-11-05', libelleType: 'Type 9');

        $props = $this->page($this->admin());
        $types = $props['repartition_types'];

        $this->assertCount(8, $types);
        $this->assertSame(['Type 9', 'Type 8', 'Type 7', 'Type 6', 'Type 5', 'Type 4', 'Type 3'], array_column(array_slice($types, 0, 7), 'label'));
        $this->assertSame(['id' => null, 'label' => 'Autres types', 'montant' => 3_000.0], $types[7]);
        $this->assertEquals($props['indicateurs']['payees']['montant'], array_sum(array_column($types, 'montant')));
    }

    public function test_la_comparaison_des_agences_est_triee_et_isole_les_depenses_sans_agence(): void
    {
        $this->depense(70_000, StatutDepense::VALIDE, '2026-11-05', site: $this->cba);
        $this->depense(100_000, StatutDepense::VALIDE, '2026-11-05');
        $this->depense(500_000, StatutDepense::SOUMIS, '2026-11-05', site: $this->cba);
        Depense::factory()->valide()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->auteur->id,
            'depense_type_id' => $this->type(CategorieDepense::INTERNE)->id,
            'site_id' => null,
            'montant' => 30_000,
            'date_depense' => '2026-11-06',
        ]);

        $agences = $this->page($this->admin())['repartition_agences'];

        $this->assertSame(['Matoto', 'Cba', 'Sans agence'], array_column($agences, 'label'));
        $this->assertSame([100_000.0, 70_000.0, 30_000.0], array_column($agences, 'montant'));
        $this->assertNull($agences[2]['id']);
    }

    public function test_le_filtre_agence_s_applique_a_toute_la_page(): void
    {
        $this->depense(100_000, StatutDepense::VALIDE, '2026-11-05');
        $this->depense(70_000, StatutDepense::VALIDE, '2026-11-05', CategorieDepense::LIVREUR, $this->cba);
        $this->depense(9_000, StatutDepense::SOUMIS, '2026-11-05', site: $this->cba);

        $props = $this->page($this->admin(), ['site_id' => $this->cba->id]);

        $this->assertSame($this->cba->id, $props['agence']);
        $this->assertEquals(70_000, $props['indicateurs']['payees']['montant']);
        $this->assertEquals(70_000, $props['indicateurs']['avances']['montant']);
        $this->assertEquals(9_000, $props['en_attente']['montant']);
        $this->assertSame(['Cba'], array_column($props['repartition_agences'], 'label'));
    }

    public function test_le_perimetre_est_celui_de_la_liste_des_depenses(): void
    {
        $manager = $this->staff(['depenses.read'], $this->matoto);
        $this->depense(100_000, StatutDepense::VALIDE, '2026-11-05');
        // Saisie par le manager dans une autre agence : visible (DepensePerimetre), comme dans la liste.
        $this->depense(20_000, StatutDepense::VALIDE, '2026-11-05', site: $this->cba, auteur: $manager);
        // Autre dépense de Cba : hors périmètre.
        $this->depense(70_000, StatutDepense::VALIDE, '2026-11-05', site: $this->cba);

        $props = $this->page($manager);

        $this->assertTrue($props['perimetre_limite']);
        $this->assertSame([['value' => $this->matoto->id, 'label' => 'Matoto']], $props['agences']);
        $this->assertEquals(120_000, $props['indicateurs']['payees']['montant']);
        $this->assertSame(['Matoto', 'Cba'], array_column($props['repartition_agences'], 'label'));
        $this->assertSame([100_000.0, 20_000.0], array_column($props['repartition_agences'], 'montant'));
    }

    public function test_la_permission_toutes_agences_ouvre_toute_l_organisation(): void
    {
        $lecteur = $this->staff(['depenses.read', 'depenses.lecture_toutes_agences'], $this->matoto);
        $this->depense(100_000, StatutDepense::VALIDE, '2026-11-05');
        $this->depense(70_000, StatutDepense::VALIDE, '2026-11-05', site: $this->cba);

        $props = $this->page($lecteur);

        $this->assertFalse($props['perimetre_limite']);
        $this->assertEquals(170_000, $props['indicateurs']['payees']['montant']);
        $this->assertCount(2, $props['agences']);
    }

    public function test_une_agence_hors_perimetre_est_refusee(): void
    {
        $manager = $this->staff(['depenses.read'], $this->matoto);

        $this->actingAs($manager)
            ->get(route('dashboard.depenses', ['site_id' => $this->cba->id]))
            ->assertSessionHasErrors(['site_id' => 'Agence inconnue ou hors de votre périmètre.']);
    }

    public function test_l_acces_exige_la_lecture_des_depenses_et_le_module(): void
    {
        $this->actingAs($this->staff([], $this->matoto))
            ->get(route('dashboard.depenses'))
            ->assertForbidden();

        $admin = $this->admin();
        Feature::for($this->org)->deactivate(ModuleFeature::DEPENSES);
        $this->actingAs($admin)
            ->get(route('dashboard.depenses'))
            ->assertForbidden();
    }

    public function test_une_depense_rejetee_puis_soumise_de_nouveau_n_est_comptee_qu_une_fois(): void
    {
        $depense = $this->depense(15_000, StatutDepense::REJETE, '2026-11-05');
        $depense->update(['statut' => StatutDepense::SOUMIS->value, 'motif_rejet' => null]);

        $props = $this->page($this->admin());

        $this->assertEquals(['montant' => 0, 'nombre' => 0, 'taux' => null], $props['rejetees']);
        $this->assertSame(1, $props['en_attente']['nombre']);
        $this->assertEquals(15_000, $props['indicateurs']['total']['montant']);
    }

    public function test_sans_donnees_tout_est_a_zero(): void
    {
        $props = $this->page($this->admin());

        foreach (['total', 'payees', 'charges', 'avances'] as $cle) {
            $this->assertEquals(['montant' => 0, 'evolution' => null], $props['indicateurs'][$cle]);
        }
        $this->assertEquals(['montant' => 0, 'nombre' => 0, 'plus_ancienne' => null, 'anciennete_jours' => null], $props['en_attente']);
        $this->assertEquals(['montant' => 0, 'nombre' => 0, 'taux' => null], $props['rejetees']);
        $this->assertSame([], $props['repartition_types']);
        $this->assertSame([], $props['repartition_agences']);
        $this->assertCount(22, $props['evolution']['tranches']);
    }

    public function test_trois_requetes_sur_les_depenses_quel_que_soit_le_volume(): void
    {
        foreach (range(1, 30) as $n) {
            $site = $n % 2 === 0 ? $this->matoto : $this->cba;
            $this->depense($n * 100, StatutDepense::VALIDE, '2026-11-'.str_pad((string) (($n % 20) + 1), 2, '0', STR_PAD_LEFT), site: $site, libelleType: 'Type '.($n % 10));
        }
        $admin = $this->admin();

        DB::enableQueryLog();
        $this->page($admin);
        $requetes = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, '"depenses"') || str_contains($sql, '`depenses`'));
        DB::disableQueryLog();

        $this->assertCount(3, $requetes, $requetes->implode(PHP_EOL));
    }
}
