<?php

namespace Tests\Feature\Stock;

use App\Models\Organization;
use App\Models\Site;
use App\Models\VarianteStock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Preuve de concurrence RÉELLE — deux connexions MySQL réellement distinctes (l'une dans ce
 * processus, l'autre dans un sous-processus PHP lancé en parallèle via proc_open), pas une
 * simple suite d'appels séquentiels dans un seul processus PHP. Un test « concurrent » qui ne
 * fait qu'enchaîner deux appels l'un après l'autre ne prouve rien sur la résistance réelle à une
 * course (constat de l'audit stock du 25/08/2026, corrigeant un test antérieur nommé ainsi mais
 * en réalité séquentiel).
 *
 * Nécessite un serveur MySQL local accessible ET la base dédiée `elm_monolithe_concurrency_test`
 * déjà migrée (cf. config/database.php, connexions mysql_testing / mysql_testing_2 — JAMAIS la
 * base de dev, jamais le sqlite en mémoire forcé par phpunit.xml pour le reste de la suite). Se
 * SKIP proprement (jamais un échec) si l'environnement ne le permet pas — ce test dépend d'une
 * infrastructure locale, contrairement au reste de la suite.
 *
 * N'utilise PAS RefreshDatabase : cette connexion est hors du scope sqlite forcé par
 * phpunit.xml. La base est entièrement nettoyée (TRUNCATE, FK désactivées) en amont de chaque
 * test plutôt que suivie ligne par ligne — cette base n'a aucun autre usage que ce fichier.
 */
class VarianteStockConcurrenceTest extends TestCase
{
    use HasProduitVariante;

    private const CONNECTION = 'mysql_testing';

    private ?string $syncDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->mysqlTestingDisponible()) {
            $this->markTestSkipped(
                'Connexion mysql_testing indisponible — vérifier qu\'un serveur MySQL local tourne '.
                'et que la base elm_monolithe_concurrency_test existe et est migrée '.
                '(php artisan migrate --database=mysql_testing).'
            );
        }

        $this->reinitialiserBaseTest();
    }

    protected function tearDown(): void
    {
        if ($this->syncDir && is_dir($this->syncDir)) {
            foreach (glob($this->syncDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->syncDir);
        }

        DB::purge('mysql_testing');
        DB::purge('mysql_testing_2');

        parent::tearDown();
    }

    private function mysqlTestingDisponible(): bool
    {
        try {
            DB::connection('mysql_testing')->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Base dédiée exclusivement à ce test : un TRUNCATE complet (FK désactivées le temps de
     * l'opération) est plus simple et plus rapide qu'un suivi précis des lignes créées, et sans
     * risque puisqu'aucun autre test ni processus n'écrit dans cette base.
     */
    private function reinitialiserBaseTest(): void
    {
        $conn = DB::connection('mysql_testing');
        $conn->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['variante_stocks', 'stock_reservations', 'produit_variantes', 'produits', 'produit_types', 'sites', 'organizations'] as $table) {
            $conn->table($table)->truncate();
        }
        $conn->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Crée organisation/site/produit/variante directement sur la connexion mysql_testing — bascule
     * temporairement la connexion PAR DÉFAUT de l'application le temps de la création (les
     * factories/le trait HasProduitVariante écrivent toujours sur la connexion par défaut), pour
     * réutiliser exactement la même mécanique que le reste de la suite plutôt que de dupliquer
     * des inserts bruts pour chaque table parente.
     *
     * @return array{orgId: string, siteId: string, varianteId: string}
     */
    private function creerDonneesDeBase(): array
    {
        $defautPrecedent = config('database.default');
        config(['database.default' => self::CONNECTION]);

        try {
            $org = Organization::factory()->create();
            $site = Site::create([
                'organization_id' => $org->id,
                'nom' => 'Site Concurrence',
                'type' => 'depot',
                'localisation' => 'Conakry',
            ]);
            $produit = $this->makeProduitAvecVariante($org, ['nom' => 'Produit Concurrence']);
            $varianteId = $produit->variantePrincipale()->first()->id;
        } finally {
            config(['database.default' => $defautPrecedent]);
        }

        return ['orgId' => $org->id, 'siteId' => $site->id, 'varianteId' => $varianteId];
    }

    /**
     * Reproduit fidèlement la course documentée dans VarianteStock::lockOuCreer() : deux
     * transactions sur deux connexions MySQL réellement distinctes (l'une pilotée par ce test,
     * l'autre par un sous-processus PHP lancé en parallèle) constatent toutes deux l'absence de
     * ligne pour la même (produit_variante_id, site_id), puis la transaction B insère et
     * committe pendant que la transaction A appelle le VRAI VarianteStock::lockOuCreer(). Sans
     * le correctif du 25/08/2026, ce scénario laisserait fuiter une QueryException SQL brute
     * (violation de contrainte unique) jusqu'à l'appelant métier — lockOuCreer() doit au
     * contraire la rattraper et renvoyer la ligne créée par B, sans jamais créer de doublon.
     */
    public function test_lock_ou_creer_resiste_a_une_creation_concurrente_reelle(): void
    {
        ['orgId' => $orgId, 'siteId' => $siteId, 'varianteId' => $varianteId] = $this->creerDonneesDeBase();

        $this->syncDir = sys_get_temp_dir().'/elm_concurrence_'.bin2hex(random_bytes(6));
        mkdir($this->syncDir);

        // Lance la transaction B dans un VRAI sous-processus PHP, indépendant — c'est ce qui
        // rend ce test réellement concurrent (deux connexions MySQL actives en parallèle),
        // contrairement à deux appels successifs dans un seul processus.
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $cmd = sprintf(
            '%s %s test:race-lock-ou-creer %s %s %s %s',
            escapeshellarg($php),
            escapeshellarg($artisan),
            escapeshellarg($varianteId),
            escapeshellarg($siteId),
            escapeshellarg($orgId),
            escapeshellarg($this->syncDir),
        );
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process, 'Impossible de lancer le sous-processus de test de concurrence.');

        try {
            // Attend que B ait inséré (transaction ouverte, PAS encore committée) — preuve que
            // B « tient » la ligne de façon non visible aux autres connexions avant ce signal.
            $this->attendreFichier($this->syncDir.'/b_ready', 'B n\'a jamais signalé être prêt (insert effectué, transaction ouverte).');

            // Transaction A : le VRAI primitif testé, sur une connexion MySQL distincte de B,
            // pendant que B est toujours en transaction ouverte. Selon le timing réel du
            // scheduler OS, l'INSERT interne de A soit échoue immédiatement par clé dupliquée
            // (si B a déjà committé), soit se bloque en attendant B puis échoue par clé
            // dupliquée dès que B committe — dans les deux cas, lockOuCreer() doit absorber
            // l'exception et renvoyer la ligne, jamais la laisser fuiter.
            file_put_contents($this->syncDir.'/go', '1');

            // lockOuCreer() suppose explicitement (cf. son docblock) que l'appelant s'exécute
            // déjà dans une transaction — jamais appelée nue en production (toujours depuis
            // MouvementStockService::appliquer()/StockReservationService::reserver(), eux-mêmes
            // toujours dans un DB::transaction() englobant). On reproduit ici la même condition.
            $resultat = DB::connection(self::CONNECTION)->transaction(
                fn () => VarianteStock::lockOuCreer($varianteId, $siteId, $orgId, connection: self::CONNECTION)
            );

            $this->attendreFichier($this->syncDir.'/b_done', 'Le sous-processus B ne s\'est jamais terminé.', autoriserErreur: true);

            if (file_exists($this->syncDir.'/b_error')) {
                $this->fail('Le sous-processus B a échoué : '.file_get_contents($this->syncDir.'/b_error'));
            }

            $this->assertNotNull($resultat);
            $this->assertSame($varianteId, $resultat->produit_variante_id);
            $this->assertSame($siteId, $resultat->site_id);

            // Une seule ligne au final — jamais un doublon silencieux.
            $total = DB::connection(self::CONNECTION)->table('variante_stocks')
                ->where('produit_variante_id', $varianteId)
                ->where('site_id', $siteId)
                ->count();
            $this->assertSame(1, $total);
        } finally {
            proc_close($process);
        }
    }

    /**
     * 30s (pas 10s) : le sous-processus B doit démarrer un interpréteur PHP, amorcer tout le
     * framework Laravel puis ouvrir une connexion MySQL avant de pouvoir écrire "b_ready" — sous
     * charge système forte (ex : exécuté au sein de la suite complète, des milliers de tests
     * juste avant), ce démarrage peut dépasser 10s sans qu'il s'agisse d'un défaut de
     * lockOuCreer() lui-même (constaté : 5/5 exécutions isolées passent en ~5-6s chacune ; le
     * seul échec observé survenait au sein d'une exécution de ~70 minutes de la suite complète).
     */
    private function attendreFichier(string $path, string $messageEchec, bool $autoriserErreur = false): void
    {
        $deadline = microtime(true) + 30;
        while (! file_exists($path) && microtime(true) < $deadline) {
            usleep(20000);
        }

        if (! $autoriserErreur) {
            $this->assertFileExists($path, $messageEchec);
        }
    }
}
