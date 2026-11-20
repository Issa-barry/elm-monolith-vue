<?php

namespace Tests\Feature\Tresorerie;

use App\Models\CompteTresorerie;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use App\Support\Database\ConnexionHorsTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\AmorceNumerotationComptable;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Preuve MySQL réelle de la garde commune à toutes les sorties d'argent
 * (TresorerieDisponibiliteService::garantirSoldeSuffisant(), ADR 0069 décision 9) : paiements de
 * fiche, paiement groupé, paiements fournisseur, salaires, mouvements de fonds, remboursements de
 * vente. Chaque sortie tourne dans son propre processus et sa propre connexion
 * (tests/Support/concurrence_solde_support.php), indépendamment de l'écran appelant.
 *
 * Même base dédiée que les autres tests de concurrence (connexion mysql_testing) ; se SKIP si elle
 * n'est pas disponible ou pas migrée.
 */
class SoldeSupportConcurrenceTest extends TestCase
{
    use AmorceNumerotationComptable, HasAdminSetup, HasCaissesDediees, HasOrgAndUser;

    private const CONNECTION = 'mysql_testing';

    private const DELAI_MS = 1500;

    private ?string $dossier = null;

    private ?string $defaut = null;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $disponible = Schema::connection(self::CONNECTION)->hasTable('paiement_fiche_paiements_annules');
        } catch (\Throwable) {
            $disponible = false;
        }
        if (! $disponible) {
            $this->markTestSkipped('Connexion mysql_testing indisponible ou base non migrée (php artisan migrate --database=mysql_testing).');
        }

        $this->defaut = config('database.default');
        config(['database.default' => self::CONNECTION]);
        $this->initOrgAndUser(['comptabilite.read']);
        $this->amorcerNumerotation($this->org->id);
    }

    protected function tearDown(): void
    {
        if (isset($this->org)) {
            $connexion = DB::connection(self::CONNECTION);
            $tables = $connexion->select(
                'SELECT TABLE_NAME AS nom FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = ?',
                [$connexion->getDatabaseName(), 'organization_id'],
            );
            $connexion->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($tables as $table) {
                $connexion->table($table->nom)->where('organization_id', $this->org->id)->delete();
            }
            $connexion->table('organizations')->where('id', $this->org->id)->delete();
            $connexion->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        if ($this->dossier && is_dir($this->dossier)) {
            foreach (glob($this->dossier.'/*') ?: [] as $fichier) {
                @unlink($fichier);
            }
            @rmdir($this->dossier);
        }

        if ($this->defaut !== null) {
            config(['database.default' => $this->defaut]);
        }
        DB::purge(self::CONNECTION);

        parent::tearDown();
    }

    /**
     * @param  list<array{support: CompteTresorerie, montant: float}>  $sorties
     * @return list<array<string, mixed>>
     */
    private function enMemeTemps(array $sorties): array
    {
        $this->dossier = sys_get_temp_dir().'/elm_concurrence_solde_'.bin2hex(random_bytes(6));
        mkdir($this->dossier);

        $processus = [];
        foreach ($sorties as $i => $sortie) {
            // Un auteur par sortie : il sert aussi de source à sa pièce (idempotence des pièces).
            $auteur = User::factory()->create(['organization_id' => $this->org->id]);
            file_put_contents("{$this->dossier}/p{$i}_ordre.json", json_encode([
                'support_id' => $sortie['support']->id,
                'montant' => $sortie['montant'],
                'user_id' => $auteur->id,
                'delai_ms' => self::DELAI_MS,
            ]));
            $commande = sprintf('%s %s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg(base_path('tests/Support/concurrence_solde_support.php')), escapeshellarg($this->dossier), escapeshellarg("p{$i}"));
            $processus[$i] = proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, base_path());
            $this->assertIsResource($processus[$i], 'Impossible de lancer le sous-processus de concurrence.');
        }

        try {
            foreach (array_keys($sorties) as $i) {
                $this->attendre("{$this->dossier}/p{$i}_pret", 180, "DÉMARRAGE (pas une erreur fonctionnelle) : le processus {$i} n'a pas fini d'amorcer le framework en 180 s.");
            }
            file_put_contents($this->dossier.'/go', '1');

            $resultats = [];
            foreach (array_keys($sorties) as $i) {
                $this->attendre("{$this->dossier}/p{$i}_fini", 90, "FONCTIONNEL : la sortie {$i} n'a pas répondu 90 s après le départ commun : blocage réel à analyser.");
                $resultats[$i] = json_decode((string) file_get_contents("{$this->dossier}/p{$i}_fini"), true);
                $this->assertNull($resultats[$i]['exception'] ?? null, "Processus {$i} : ".json_encode($resultats[$i], JSON_UNESCAPED_UNICODE));
            }
        } finally {
            foreach ($processus as $p) {
                proc_close($p);
            }
        }

        $this->assertLessThan(min(array_column($resultats, 'fin')), max(array_column($resultats, 'debut')), 'Les sorties ne se sont pas chevauchées.');

        return $resultats;
    }

    private function attendre(string $fichier, int $secondes, string $messageEchec): void
    {
        $limite = microtime(true) + $secondes;
        while (! file_exists($fichier) && microtime(true) < $limite) {
            usleep(20000);
        }

        $this->assertFileExists($fichier, $messageEchec);
    }

    private function solde(CompteTresorerie $support): float
    {
        return app(TresorerieDisponibiliteService::class)->soldePourSupport($support->fresh(), now());
    }

    private function agence(): Site
    {
        return $this->user->sites()->wherePivot('is_default', true)->firstOrFail();
    }

    public function test_la_connexion_hors_transaction_est_bien_une_autre_connexion(): void
    {
        DB::transaction(function () {
            $principale = DB::connection();
            $hors = ConnexionHorsTransaction::pour($principale);

            $this->assertNotSame($principale->getName(), $hors->getName());
            $this->assertNotSame($principale->getPdo(), $hors->getPdo());
            $this->assertSame(0, $hors->transactionLevel());
            $this->assertNotSame(
                $principale->selectOne('SELECT CONNECTION_ID() AS id')->id,
                $hors->selectOne('SELECT CONNECTION_ID() AS id')->id,
            );
        });
    }

    public function test_la_connexion_hors_transaction_lit_le_serveur_principal_meme_avec_un_replica(): void
    {
        // Réplica de lecture injoignable (nom réservé .invalid) : toute lecture qui irait vers lui
        // échouerait aussitôt.
        $config = config('database.connections.'.self::CONNECTION);
        config(['database.connections.mysql_avec_replica' => [
            ...$config,
            'read' => ['host' => ['replica.invalid']],
            'write' => ['host' => [$config['host']]],
            'sticky' => false,
        ]]);

        try {
            DB::connection('mysql_avec_replica')->transaction(function ($principale) {
                $hors = ConnexionHorsTransaction::pour($principale);

                $this->assertArrayNotHasKey('read', $hors->getConfig());
                $this->assertSame($hors->getPdo(), $hors->getReadPdo(), 'Lectures et écritures sur le même serveur.');
                $this->assertSame(1, (int) $hors->selectOne('SELECT 1 AS un')->un);
            });
        } finally {
            DB::purge('mysql_avec_replica');
            DB::purge('mysql_avec_replica__hors_transaction');
        }
    }

    public function test_deux_sorties_simultanees_dun_support_ne_le_rendent_jamais_negatif(): void
    {
        $caisse = $this->creerSupportAgence($this->agence()->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($caisse, 400_000);

        $resultats = $this->enMemeTemps([
            ['support' => $caisse, 'montant' => 300_000],
            ['support' => $caisse, 'montant' => 300_000],
        ]);

        // Le second a figé sa vue avant que le premier ne valide : il doit pourtant voir sa sortie.
        $statuts = array_column($resultats, 'statut');
        sort($statuts);
        $this->assertSame(['ok', 'refus'], $statuts, json_encode($resultats, JSON_UNESCAPED_UNICODE));
        $this->assertStringStartsWith('Solde insuffisant : 100 000 GNF disponible', collect($resultats)->firstWhere('statut', 'refus')['refus']);
        $this->assertSame(100_000.0, $this->solde($caisse));
    }

    public function test_sorties_simultanees_de_deux_agences_sur_le_meme_compte_passent_toutes(): void
    {
        $orange = $this->creerSupportAgence($this->agence()->id, 'mobile_money', '561100', 'orange_money');
        $kindia = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kindia', 'type' => 'depot', 'localisation' => 'Kindia']);
        $orangeKindia = $this->creerSupportAgence($kindia->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($orange, 400_000);
        $this->alimenterCaisse($orangeKindia, 400_000);

        $resultats = $this->enMemeTemps([
            ['support' => $orange, 'montant' => 300_000],
            ['support' => $orangeKindia, 'montant' => 300_000],
        ]);

        // Une lecture verrouillante du grand livre les faisait s'interbloquer : l'une était refusée.
        $this->assertSame(['ok', 'ok'], array_column($resultats, 'statut'), json_encode($resultats, JSON_UNESCAPED_UNICODE));
        $this->assertSame(100_000.0, $this->solde($orange));
        $this->assertSame(100_000.0, $this->solde($orangeKindia));
    }

    public function test_sorties_simultanees_de_trois_caisses_de_trois_agences(): void
    {
        // Compte 571000 commun aux caisses de toutes les agences : le cas le plus fréquent en production.
        $caisses = [$this->creerSupportAgence($this->agence()->id, 'caisse', '571000')];
        foreach (['Kindia', 'Boké'] as $nom) {
            $site = Site::create(['organization_id' => $this->org->id, 'nom' => $nom, 'type' => 'depot', 'localisation' => $nom]);
            $caisses[] = $this->creerSupportAgence($site->id, 'caisse', '571000');
        }
        foreach ($caisses as $caisse) {
            $this->alimenterCaisse($caisse, 500_000);
        }

        $resultats = $this->enMemeTemps(array_map(fn (CompteTresorerie $c) => ['support' => $c, 'montant' => 200_000], $caisses));

        $this->assertSame(['ok', 'ok', 'ok'], array_column($resultats, 'statut'), json_encode($resultats, JSON_UNESCAPED_UNICODE));
        foreach ($caisses as $caisse) {
            $this->assertSame(300_000.0, $this->solde($caisse));
        }
    }
}
