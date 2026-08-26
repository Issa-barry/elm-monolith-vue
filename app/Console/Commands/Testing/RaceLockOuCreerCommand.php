<?php

namespace App\Console\Commands\Testing;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Support de test UNIQUEMENT — utilisé par
 * tests/Feature/Stock/VarianteStockConcurrenceTest.php pour simuler la transaction B d'une
 * course réelle sur VarianteStock::lockOuCreer() : un sous-processus PHP indépendant, avec sa
 * propre connexion MySQL (mysql_testing_2), qui insère la ligne SANS committer immédiatement,
 * signale qu'il est prêt, attend le signal du processus principal (fichier "go"), puis committe.
 *
 * Ne réutilise délibérément PAS VarianteStock::lockOuCreer() : le but de ce sous-processus est
 * de contrôler précisément l'instant où l'INSERT est émis (juste avant le signal "prêt"), pas
 * de rejouer le primitif que le test cherche justement à mettre à l'épreuve.
 *
 * Jamais appelé en dehors de ce test — sans effet destructeur si lancé par erreur (transaction
 * annulée après le timeout d'attente).
 */
class RaceLockOuCreerCommand extends Command
{
    protected $signature = 'test:race-lock-ou-creer {varianteId} {siteId} {orgId} {syncDir}';

    protected $description = 'Support de test de concurrence stock — réservé à VarianteStockConcurrenceTest, ne pas utiliser autrement.';

    public function handle(): int
    {
        $varianteId = $this->argument('varianteId');
        $siteId = $this->argument('siteId');
        $orgId = $this->argument('orgId');
        $syncDir = $this->argument('syncDir');

        $conn = DB::connection('mysql_testing_2');
        $conn->beginTransaction();

        try {
            $existe = $conn->table('variante_stocks')
                ->where('produit_variante_id', $varianteId)
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->exists();

            if (! $existe) {
                $conn->table('variante_stocks')->insert([
                    'id' => (string) Str::ulid(),
                    'organization_id' => $orgId,
                    'produit_variante_id' => $varianteId,
                    'site_id' => $siteId,
                    'qte_stock' => 0,
                    'qte_reservee' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            file_put_contents($syncDir.'/b_ready', '1');

            // 30s : même marge que l'attente symétrique côté test principal (cf.
            // VarianteStockConcurrenceTest::attendreFichier()) — reste borné, jamais un blocage
            // indéfini si le processus principal ne signale jamais "go".
            $deadline = microtime(true) + 30;
            while (! file_exists($syncDir.'/go') && microtime(true) < $deadline) {
                usleep(20000);
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            file_put_contents($syncDir.'/b_error', (string) $e);

            return self::FAILURE;
        }

        file_put_contents($syncDir.'/b_done', '1');

        return self::SUCCESS;
    }
}
