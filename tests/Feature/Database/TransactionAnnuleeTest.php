<?php

namespace Tests\Feature\Database;

use App\Support\Database\Connexions\SQLiteConnexionGardee;
use App\Support\Database\TransactionAnnuleeException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\SimuleDeadlock;
use Tests\TestCase;
use Throwable;

/**
 * Incident VTE-081126-134 (08/11/2026) : un deadlock masqué par un catch laissait le code continuer
 * hors transaction — la pièce de caisse et l'audit restaient en base, l'encaissement avait disparu.
 * Sans RefreshDatabase : les transactions testées sont les vraies transactions de la connexion.
 */
class TransactionAnnuleeTest extends TestCase
{
    use SimuleDeadlock;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE TABLE IF NOT EXISTS operations (id INTEGER PRIMARY KEY, libelle TEXT)');
        DB::table('operations')->delete();
        $this->preparerDeadlockSimule();
    }

    public function test_la_connexion_de_l_application_porte_la_garde(): void
    {
        $this->assertInstanceOf(SQLiteConnexionGardee::class, DB::connection());
    }

    public function test_une_ecriture_apres_un_deadlock_masque_est_refusee_et_rien_n_est_valide(): void
    {
        $this->expectException(TransactionAnnuleeException::class);

        try {
            DB::transaction(function () {
                DB::table('operations')->insert(['libelle' => 'avant']);
                $this->deadlockMasque();
                DB::table('operations')->insert(['libelle' => 'après']);
            });
        } finally {
            $this->assertSame(0, DB::table('operations')->count());
        }
    }

    public function test_une_transaction_dont_toutes_les_erreurs_sont_masquees_n_est_jamais_validee(): void
    {
        $this->expectException(TransactionAnnuleeException::class);

        try {
            DB::transaction(function () {
                DB::table('operations')->insert(['libelle' => 'avant']);
                $this->deadlockMasque();
                try {
                    DB::table('operations')->insert(['libelle' => 'après']);
                } catch (Throwable) {
                    // second masquage : seule la validation finale peut encore refuser
                }
            });
        } finally {
            $this->assertSame(0, DB::table('operations')->count());
        }
    }

    public function test_un_deadlock_masque_dans_une_transaction_imbriquee_bloque_l_operation_principale(): void
    {
        $this->expectException(TransactionAnnuleeException::class);

        try {
            DB::transaction(function () {
                DB::table('operations')->insert(['libelle' => 'encaissement']);
                try {
                    DB::transaction(fn () => $this->requeteEnDeadlock());
                } catch (Throwable) {
                    // comme la réouverture automatique de période : erreur journalisée puis masquée
                }
                DB::table('operations')->insert(['libelle' => 'pièce de caisse']);
            });
        } finally {
            $this->assertSame(0, DB::table('operations')->count());
        }
    }

    public function test_la_tentative_suivante_repart_sur_une_transaction_saine(): void
    {
        $tentatives = 0;

        DB::transaction(function () use (&$tentatives) {
            $tentatives++;
            DB::table('operations')->insert(['libelle' => 'tentative '.$tentatives]);
            if ($tentatives === 1) {
                $this->deadlockMasque();
            }
        }, 2);

        $this->assertSame(2, $tentatives);
        $this->assertSame(['tentative 2'], DB::table('operations')->pluck('libelle')->all());
    }

    public function test_une_nouvelle_operation_apres_l_echec_s_enregistre_normalement(): void
    {
        try {
            DB::transaction(function () {
                $this->deadlockMasque();
                DB::table('operations')->insert(['libelle' => 'perdu']);
            });
        } catch (TransactionAnnuleeException) {
            // attendu
        }

        DB::transaction(fn () => DB::table('operations')->insert(['libelle' => 'nouvelle opération']));

        $this->assertSame(['nouvelle opération'], DB::table('operations')->pluck('libelle')->all());
    }

    public function test_une_erreur_ordinaire_masquee_ne_bloque_pas_la_transaction(): void
    {
        DB::transaction(function () {
            DB::table('operations')->insert(['libelle' => 'avant']);
            try {
                DB::table('table_inexistante')->insert(['x' => 1]);
            } catch (Throwable) {
                // erreur sans rapport avec la concurrence : la transaction reste valide
            }
            DB::table('operations')->insert(['libelle' => 'après']);
        });

        $this->assertSame(2, DB::table('operations')->count());
    }

    public function test_hors_transaction_un_deadlock_ne_bloque_pas_les_requetes_suivantes(): void
    {
        $this->deadlockMasque();

        DB::table('operations')->insert(['libelle' => 'hors transaction']);

        $this->assertSame(1, DB::table('operations')->count());
    }
}
