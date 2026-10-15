<?php

namespace Tests\Feature\Tresorerie;

use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Preuve de concurrence RÉELLE pour les comptes Mobile Money d'agents (ADR 0027) : plusieurs
 * créations lancées au même instant, chacune dans son propre processus PHP et sur sa propre
 * connexion MySQL. Le reste de la suite tourne en SQLite, qui ne reproduit ni les verrous de
 * ligne ni l'instantané de lecture de MySQL (REPEATABLE READ) : c'est précisément ce dernier qui
 * laissait une création concurrente relire un état périmé après son attente du verrou.
 *
 * Même infrastructure que Stock\VarianteStockConcurrenceTest : base dédiée
 * `elm_monolithe_concurrency_test` (connexion mysql_testing), jamais la base de dev. Se SKIP si
 * elle n'est pas disponible ou pas migrée (php artisan migrate --database=mysql_testing).
 */
class CompteMobileMoneyAgentConcurrenceTest extends TestCase
{
    private const CONNECTION = 'mysql_testing';

    private ?string $dossier = null;

    private ?string $organizationId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->baseDisponible()) {
            $this->markTestSkipped(
                'Connexion mysql_testing indisponible ou base non migrée — vérifier qu\'un serveur MySQL local '.
                'tourne et que elm_monolithe_concurrency_test est migrée (php artisan migrate --database=mysql_testing).'
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->organizationId !== null) {
            $this->supprimerOrganisation($this->organizationId);
        }

        if ($this->dossier && is_dir($this->dossier)) {
            foreach (glob($this->dossier.'/*') ?: [] as $fichier) {
                @unlink($fichier);
            }
            @rmdir($this->dossier);
        }

        DB::purge(self::CONNECTION);

        parent::tearDown();
    }

    private function baseDisponible(): bool
    {
        try {
            return Schema::connection(self::CONNECTION)->hasColumn('compta_supports_tresorerie', 'numero');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Lignes de cette organisation seulement : la base est partagée avec l'autre test de concurrence. */
    private function supprimerOrganisation(string $organizationId): void
    {
        $connexion = DB::connection(self::CONNECTION);
        $tables = $connexion->select(
            'SELECT TABLE_NAME AS nom FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = ?',
            [$connexion->getDatabaseName(), 'organization_id'],
        );

        $connexion->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $connexion->table($table->nom)->where('organization_id', $organizationId)->delete();
        }
        $connexion->table('organizations')->where('id', $organizationId)->delete();
        $connexion->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Organisation, plan comptable, agence et agents créés sur mysql_testing, avec la même mécanique
     * que le reste de la suite (connexion par défaut basculée le temps de la création).
     *
     * @return array{site: string, agents: list<string>}
     */
    private function preparer(int $nombreAgents): array
    {
        $defaut = config('database.default');
        config(['database.default' => self::CONNECTION]);

        try {
            $org = Organization::factory()->create();
            $this->organizationId = $org->id;
            if (! CompteComptable::where('organization_id', $org->id)->where('numero', '561100')->exists()) {
                app(PlanComptableBootstrapService::class)->bootstrap($org->id);
            }

            $site = Site::create(['organization_id' => $org->id, 'nom' => 'Agence Concurrence', 'type' => 'agence', 'localisation' => 'Conakry']);

            $agents = [];
            for ($i = 1; $i <= $nombreAgents; $i++) {
                $agent = User::factory()->create(['organization_id' => $org->id, 'prenom' => 'Agent', 'nom' => "Numéro {$i}"]);
                $agent->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
                $agents[] = $agent->id;
            }
        } finally {
            config(['database.default' => $defaut]);
        }

        return ['site' => $site->id, 'agents' => $agents];
    }

    /**
     * Lance une création par numéro, toutes au même instant, et rend le résultat de chacune.
     *
     * @param  list<string>  $agents
     * @param  list<string>  $numeros
     * @return list<array<string, mixed>>
     */
    private function creerEnMemeTemps(string $siteId, array $agents, array $numeros): array
    {
        $this->dossier = sys_get_temp_dir().'/elm_concurrence_mm_'.bin2hex(random_bytes(6));
        mkdir($this->dossier);

        $processus = [];
        foreach ($numeros as $i => $numero) {
            $commande = sprintf(
                '%s %s %s %s %s %s %s %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(base_path('tests/Support/concurrence_compte_mobile_money.php')),
                escapeshellarg($this->organizationId),
                escapeshellarg($siteId),
                escapeshellarg($agents[$i]),
                escapeshellarg($numero),
                escapeshellarg($this->dossier),
                escapeshellarg("p{$i}"),
            );
            $processus[$i] = proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, base_path());
            $this->assertIsResource($processus[$i], 'Impossible de lancer le sous-processus de concurrence.');
        }

        try {
            foreach (array_keys($numeros) as $i) {
                $this->attendre("{$this->dossier}/p{$i}_pret", "Le processus {$i} n'a jamais été prêt.");
            }

            file_put_contents($this->dossier.'/go', '1');

            $resultats = [];
            foreach (array_keys($numeros) as $i) {
                $this->attendre("{$this->dossier}/p{$i}_fini", "Le processus {$i} ne s'est jamais terminé.");
                $resultats[$i] = json_decode((string) file_get_contents("{$this->dossier}/p{$i}_fini"), true);
            }

            return $resultats;
        } finally {
            foreach ($processus as $p) {
                proc_close($p);
            }
        }
    }

    /** 90 s : chaque sous-processus amorce tout le framework avant de signaler qu'il est prêt. */
    private function attendre(string $fichier, string $messageEchec): void
    {
        $limite = microtime(true) + 90;
        while (! file_exists($fichier) && microtime(true) < $limite) {
            usleep(20000);
        }

        $this->assertFileExists($fichier, $messageEchec);
    }

    public function test_des_creations_simultanees_numerotent_a_la_suite_sans_doublon_ni_erreur(): void
    {
        ['site' => $siteId, 'agents' => $agents] = $this->preparer(6);

        $resultats = $this->creerEnMemeTemps($siteId, $agents, [
            '620000001', '620000002', '620000003', '620000004', '620000005', '620000006',
        ]);

        foreach ($resultats as $i => $resultat) {
            $this->assertTrue($resultat['ok'] ?? false, "Création {$i} refusée : ".json_encode($resultat, JSON_UNESCAPED_UNICODE));
        }

        $comptes = array_column($resultats, 'compte');
        sort($comptes);
        $this->assertSame(['561100001', '561100002', '561100003', '561100004', '561100005', '561100006'], $comptes);

        $this->assertSame(6, DB::connection(self::CONNECTION)->table('compta_supports_tresorerie')
            ->where('organization_id', $this->organizationId)->whereNotNull('agent_id')->count());
    }

    public function test_deux_creations_simultanees_du_meme_portefeuille_n_en_laissent_passer_qu_une(): void
    {
        ['site' => $siteId, 'agents' => $agents] = $this->preparer(6);

        // Trois portefeuilles, chacun demandé deux fois au même instant par deux agents différents,
        // sous deux écritures (espaces, indicatif, préfixe international).
        $resultats = $this->creerEnMemeTemps($siteId, $agents, [
            '620000001', '+224 620 00 00 01',
            '620000002', '00224620000002',
            '620 000 003', '620-000-003',
        ]);

        foreach ($resultats as $i => $resultat) {
            $this->assertArrayNotHasKey('exception', $resultat, "Création {$i} : ".json_encode($resultat, JSON_UNESCAPED_UNICODE));
        }

        foreach ([[0, 1], [2, 3], [4, 5]] as [$a, $b]) {
            $reussites = array_filter([$resultats[$a], $resultats[$b]], fn (array $r) => $r['ok']);
            $refus = array_filter([$resultats[$a], $resultats[$b]], fn (array $r) => ! $r['ok']);

            $this->assertCount(1, $reussites, "Portefeuille {$a}/{$b} : une seule création doit réussir. ".json_encode([$resultats[$a], $resultats[$b]], JSON_UNESCAPED_UNICODE));
            $this->assertArrayHasKey('numero', array_values($refus)[0]['erreurs'], 'le refus porte sur le numéro, jamais sur la numérotation comptable');
        }

        $supports = DB::connection(self::CONNECTION)->table('compta_supports_tresorerie')
            ->where('organization_id', $this->organizationId)->whereNotNull('agent_id')->pluck('numero');
        $this->assertCount(3, $supports);
        $this->assertCount(3, $supports->map(fn (string $n) => substr(CompteTresorerie::normaliserNumero($n), -9))->unique());

        $sousComptes = DB::connection(self::CONNECTION)->table('compta_comptes')
            ->where('organization_id', $this->organizationId)->where('numero', 'like', '561100___')->orderBy('numero')->pluck('numero')->all();
        $this->assertSame(['561100001', '561100002', '561100003'], $sousComptes, 'un refus ne laisse aucun sous-compte orphelin');
    }
}
