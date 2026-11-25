<?php

namespace Tests\Feature\Depenses;

use App\Enums\StatutDepense;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Support\Depenses\DepenseListingService;
use App\Support\Depenses\DepensePerimetre;
use App\Support\Periodes\Periode;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Performances de la liste des dépenses (docs/depenses-filtres.md, « Performances ») :
 * - le total de la pagination est repris des statistiques, sans second COUNT, et reste identique
 *   (total, nombre de pages, lignes de chaque page) quels que soient les filtres ;
 * - l'index (organization_id, statut, date_depense, …) n'est imposé qu'aux statistiques, avec un
 *   filtre de statut et sans filtre d'agence, de type, de catégorie ou de personne concernée, et
 *   seulement sous MySQL et MariaDB ; les lignes gardent le plan du moteur (index de tri) ;
 * - les index de la migration du 25/11/2026 sont en place.
 */
class DepenseListePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $siteA;

    private Site $siteB;

    private DepenseType $carburant;

    private DepenseType $loyer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-22 10:00:00');

        $this->org = Organization::factory()->create();
        $this->siteA = $this->site('Agence A');
        $this->siteB = $this->site('Agence B');
        $this->carburant = DepenseType::factory()->interne()->create(['organization_id' => $this->org->id, 'libelle' => 'Carburant']);
        $this->loyer = DepenseType::factory()->interne()->create(['organization_id' => $this->org->id, 'libelle' => 'Loyer']);

        foreach ([...PermissionCatalog::crudPermissionNames(), ...array_keys(PermissionCatalog::STANDALONE)] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $role->givePermissionTo('depenses.read');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->admin->assignRole($role);
        $this->admin->sites()->attach($this->siteA->id, ['role' => 'employe', 'is_default' => true]);

        // 75 dépenses : statuts, agences, types et dates (novembre, octobre, 2025) variés.
        $statuts = [StatutDepense::VALIDE, StatutDepense::SOUMIS, StatutDepense::REJETE, StatutDepense::BROUILLON, StatutDepense::VALIDE];
        $dates = ['2026-11-03', '2026-11-15', '2026-10-20', '2025-06-10', '2026-11-21'];
        for ($i = 0; $i < 75; $i++) {
            Depense::factory()->create([
                'organization_id' => $this->org->id,
                'user_id' => $this->admin->id,
                'depense_type_id' => ($i % 3 === 0 ? $this->loyer : $this->carburant)->id,
                'site_id' => ($i % 2 === 0 ? $this->siteA : $this->siteB)->id,
                'montant' => 1_000 + $i * 10,
                'date_depense' => $dates[$i % 5],
                'statut' => $statuts[intdiv($i, 5) % 5]->value,
                'commentaire' => 'DEP-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                // Saisies distinctes : l'ordre de la liste (date, puis saisie) est sans ex aequo.
                'created_at' => now()->subMinutes($i),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function site(string $nom): Site
    {
        return Site::create(['organization_id' => $this->org->id, 'nom' => $nom, 'type' => 'depot', 'localisation' => 'Conakry']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function filtres(): array
    {
        return [
            'sans filtre' => [[]],
            'ce mois' => [['periode' => 'ce_mois']],
            'cette année' => [['periode' => 'cette_annee']],
            'statut (valeur simple, comme l’écran)' => [['statut' => 'valide']],
            'statut' => [['statut' => ['valide']]],
            'statut et période' => [['statut' => ['valide'], 'periode' => 'ce_mois']],
            'plusieurs statuts' => [['statut' => ['valide', 'soumis']]],
            'agence' => [['site_ids' => ['A']]],
            'type' => [['type' => ['loyer']]],
            'statut, agence et type' => [['statut' => ['valide'], 'site_ids' => ['B'], 'type' => ['carburant'], 'periode' => 'cette_annee']],
            'recherche et statut' => [['statut' => ['soumis'], 'search' => 'DEP-1']],
            'dates libres' => [['date_debut' => '2026-10-01', 'date_fin' => '2026-11-15']],
        ];
    }

    /** Remplace les alias de l'URL des fournisseurs par les identifiants réels. */
    private function parametres(array $filtres): array
    {
        if (isset($filtres['site_ids'])) {
            $filtres['site_ids'] = array_map(fn ($s) => $s === 'A' ? $this->siteA->id : $this->siteB->id, $filtres['site_ids']);
        }
        if (isset($filtres['type'])) {
            $filtres['type'] = array_map(fn ($t) => $t === 'loyer' ? $this->loyer->id : $this->carburant->id, $filtres['type']);
        }

        return $filtres;
    }

    /** Référence : les dépenses attendues, filtrées en PHP sans passer par la liste. */
    private function attendues(array $filtres)
    {
        $periode = Periode::depuisRequete(request()->duplicate($filtres));

        return Depense::where('organization_id', $this->org->id)
            ->orderByDesc('date_depense')->orderByDesc('created_at')
            ->get()
            ->filter(fn (Depense $d) => empty($filtres['statut']) || in_array($d->statut->value, (array) $filtres['statut'], true))
            ->filter(fn (Depense $d) => empty($filtres['site_ids']) || in_array($d->site_id, $filtres['site_ids'], true))
            ->filter(fn (Depense $d) => empty($filtres['type']) || in_array($d->depense_type_id, $filtres['type'], true))
            ->filter(fn (Depense $d) => empty($filtres['search']) || str_contains((string) $d->commentaire, $filtres['search']))
            ->filter(fn (Depense $d) => ! $periode->estBornee()
                || ($d->date_depense->toDateString() >= $periode->debut->toDateString() && $d->date_depense->toDateString() <= $periode->fin->toDateString()))
            ->values();
    }

    #[DataProvider('filtres')]
    public function test_pagination_et_statistiques_identiques_avec_ou_sans_filtres(array $filtres): void
    {
        $filtres = $this->parametres($filtres);
        $attendues = $this->attendues($filtres);
        $pages = max(1, (int) ceil($attendues->count() / 30));

        foreach (range(1, $pages + 1) as $page) {
            $this->actingAs($this->admin)
                ->get(route('depenses.index', [...$filtres, 'page' => $page]))
                ->assertOk()
                ->assertInertia(fn (Assert $p) => $p
                    ->where('depenses.total', $attendues->count())
                    ->where('depenses.last_page', $pages)
                    ->where('depenses.current_page', $page)
                    ->where('stats.total', $attendues->count())
                    ->where('stats.montant_total', fn ($m) => abs((float) $m - (float) $attendues->sum('montant')) < 0.01)
                    ->where('stats.validees', $attendues->where('statut', StatutDepense::VALIDE)->count())
                    ->where('stats.en_attente', $attendues->where('statut', StatutDepense::SOUMIS)->count())
                    ->where('depenses.data', fn ($lignes) => collect($lignes)->pluck('id')->all()
                        === $attendues->forPage($page, 30)->pluck('id')->values()->all()));
        }
    }

    public function test_les_depenses_de_meme_date_et_meme_saisie_sont_departagees_par_identifiant(): void
    {
        // 31 dépenses de même date et même seconde de saisie, à cheval sur deux pages de 30.
        $exAequo = collect(range(1, 31))->map(fn () => Depense::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->admin->id,
            'depense_type_id' => $this->carburant->id,
            'site_id' => $this->siteA->id,
            'date_depense' => '2026-11-22',
            'statut' => StatutDepense::SOUMIS->value,
            'created_at' => '2026-11-22 09:00:00',
        ]))->pluck('id')->sortDesc()->values()->all();

        $ids = [];
        foreach ([1, 2] as $page) {
            $this->actingAs($this->admin)
                ->get(route('depenses.index', ['periode' => 'aujourd_hui', 'page' => $page]))
                ->assertOk()
                ->assertInertia(function (Assert $p) use (&$ids) {
                    $p->where('depenses.data', function ($lignes) use (&$ids) {
                        $ids = [...$ids, ...collect($lignes)->pluck('id')->all()];

                        return true;
                    });
                });
        }

        // Ordre stable (identifiant décroissant), chaque dépense une seule fois sur les deux pages.
        $this->assertSame($exAequo, $ids);
    }

    public function test_la_pagination_ne_relance_pas_de_count_separe(): void
    {
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('depenses.index', ['page' => 2]))->assertOk();
        $requetes = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, '"depenses"'));
        DB::disableQueryLog();

        $this->assertCount(0, $requetes->filter(fn ($sql) => str_contains($sql, 'count(*) as aggregate')), $requetes->implode(PHP_EOL));
        $this->assertCount(1, $requetes->filter(fn ($sql) => str_contains($sql, 'COUNT(*) as total')));
    }

    public function test_l_export_et_l_impression_gardent_les_memes_lignes_avec_un_filtre_de_statut(): void
    {
        // Statut en valeur simple, comme l'écran l'envoie (liste déroulante à choix unique).
        $attendues = $this->attendues(['statut' => 'soumis']);

        $csv = $this->actingAs($this->admin)
            ->get(route('depenses.export.excel', ['statut' => 'soumis']))
            ->assertOk()
            ->streamedContent();
        foreach (Depense::where('organization_id', $this->org->id)->get() as $depense) {
            $attendue = $attendues->contains('id', $depense->id);
            $this->assertSame($attendue, str_contains($csv, $depense->commentaire), $depense->commentaire);
        }

        $this->actingAs($this->admin)
            ->get(route('depenses.imprimer', ['statut' => 'soumis']))
            ->assertOk()
            ->assertViewHas('sites', fn ($sites) => $sites->flatMap(fn ($s) => collect($s['rows'])->pluck('id'))->sort()->values()->all()
                === $attendues->pluck('id')->sort()->values()->all());
    }

    public function test_l_impression_accepte_plusieurs_statuts_sans_changer_l_affichage_d_un_statut_unique(): void
    {
        // Plusieurs statuts (`statut[]=…`, lien ou favori) : plus d'erreur, mêmes lignes que la liste.
        $attendues = $this->attendues(['statut' => ['soumis', 'rejete']]);
        $this->actingAs($this->admin)
            ->get(route('depenses.imprimer', ['statut' => ['soumis', 'rejete']]))
            ->assertOk()
            ->assertSee('Statut : <span>soumis, rejete</span>', false)
            ->assertViewHas('sites', fn ($sites) => $sites->flatMap(fn ($s) => collect($s['rows'])->pluck('id'))->sort()->values()->all()
                === $attendues->pluck('id')->sort()->values()->all());

        // Un seul statut, comme l'écran l'envoie : affichage inchangé.
        $this->actingAs($this->admin)
            ->get(route('depenses.imprimer', ['statut' => 'soumis']))
            ->assertOk()
            ->assertSee('Statut : <span>soumis</span>', false);

        // Sans statut : pas de ligne « Statut ».
        $this->actingAs($this->admin)
            ->get(route('depenses.imprimer'))
            ->assertOk()
            ->assertDontSee('Statut : <span>', false);
    }

    public function test_l_index_de_statut_n_est_impose_qu_avec_un_statut_et_sans_filtre_indexe(): void
    {
        $this->assertTrue(DepenseListingService::imposeIndexStatut(['statut' => ['valide']], null));
        $this->assertTrue(DepenseListingService::imposeIndexStatut(['statut' => ['valide'], 'search' => 'x', 'montant' => '10'], null));

        $this->assertFalse(DepenseListingService::imposeIndexStatut([], null), 'sans statut');
        $this->assertFalse(DepenseListingService::imposeIndexStatut(['statut' => []], null), 'statut vide');
        $this->assertFalse(DepenseListingService::imposeIndexStatut(['statut' => ['valide']], ['A']), 'agence');
        $this->assertFalse(DepenseListingService::imposeIndexStatut(['statut' => ['valide']], []), 'agence hors périmètre');
        foreach (['type', 'categorie', 'vehicule', 'vehicule_ids', 'proprietaire_id', 'concerne', 'telephone_concerne'] as $filtre) {
            $this->assertFalse(DepenseListingService::imposeIndexStatut(['statut' => ['valide'], $filtre => 'x'], null), $filtre);
        }
    }

    public function test_sous_sqlite_la_requete_garde_son_plan(): void
    {
        $listing = app(DepenseListingService::class);

        foreach ([
            $listing->query($this->admin, ['statut' => ['valide']])->toSql(),
            $listing->queryStatistiques($this->admin, ['statut' => ['valide']])->toSql(),
        ] as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('indexed by', $sql);
            $this->assertStringNotContainsStringIgnoringCase('force index', $sql);
        }
    }

    /**
     * SQL réellement produit pour MySQL (grammaire MySQL, aucune connexion ouverte) : l'index n'est
     * imposé qu'aux statistiques avec un filtre de statut ; les lignes (page, export, impression)
     * gardent le plan du moteur, qui lit l'ordre dans l'index de tri.
     */
    public function test_sous_mysql_l_index_n_est_impose_qu_aux_statistiques_avec_un_statut(): void
    {
        $listing = app(DepenseListingService::class);
        // Rôles et permissions chargés d'avance : construire les requêtes n'interroge pas la base.
        $this->admin->load('roles', 'permissions');
        $this->admin->voitToutesLesAgences();
        app(DepensePerimetre::class)->voitToutesLesAgences($this->admin);

        $defaut = DB::getDefaultConnection();
        DB::setDefaultConnection('mysql');
        try {
            $page = $listing->query($this->admin, ['statut' => 'valide'])->forPage(1, 30)->toSql();
            $statistiques = $listing->queryStatistiques($this->admin, ['statut' => 'valide'])->toSql();
            $statistiquesSansStatut = $listing->queryStatistiques($this->admin, [])->toSql();
            $statistiquesAgence = $listing->queryStatistiques($this->admin, ['statut' => 'valide'], [$this->siteA->id])->toSql();
        } finally {
            DB::setDefaultConnection($defaut);
        }

        $indication = 'force index ('.DepenseListingService::INDEX_STATUT_DATE.')';
        $this->assertStringContainsString($indication, $statistiques);
        $this->assertStringNotContainsString('force index', $page);
        $this->assertStringContainsString('order by `date_depense` desc, `created_at` desc, `id` desc', $page);
        $this->assertStringNotContainsString('force index', $statistiquesSansStatut);
        $this->assertStringNotContainsString('force index', $statistiquesAgence);
        $this->assertStringNotContainsString('order by', $statistiques);
    }

    /**
     * Migration du 25/11/2026 : index couvrant des sommes (même nom, mêmes trois premières colonnes)
     * et index de tri de la liste.
     */
    public function test_les_index_de_la_liste_et_des_statistiques_sont_en_place(): void
    {
        $colonnes = fn (string $index) => collect(DB::select('PRAGMA index_info('.$index.')'))
            ->sortBy('seqno')->pluck('name')->values()->all();

        $this->assertSame(
            ['organization_id', 'statut', 'date_depense', 'deleted_at', 'site_id', 'user_id', 'depense_type_id', 'montant'],
            $colonnes(DepenseListingService::INDEX_STATUT_DATE),
        );
        $this->assertSame(['organization_id', 'date_depense', 'created_at'], $colonnes('dep_org_date_creation_idx'));
    }
}
