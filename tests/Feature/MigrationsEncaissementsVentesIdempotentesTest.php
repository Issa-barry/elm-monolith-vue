<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ces deux migrations sont issues de la scission d'une migration unique, renommée après avoir été
 * déployée : sur une base qui a déjà joué l'ancien nom, le nouveau nom est « en attente » alors que
 * les colonnes existent déjà. Elles doivent donc pouvoir être rejouées sans « Duplicate column name »
 * (échec du déploiement de formation du 2026-09-20).
 */
class MigrationsEncaissementsVentesIdempotentesTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = [
        'reference_paiement' => '2026_09_14_120000_add_reference_paiement_to_encaissements_ventes_table.php',
        'operateur_mobile_money' => '2026_09_15_120000_add_operateur_mobile_money_to_encaissements_ventes_table.php',
    ];

    public function test_rejouer_up_quand_la_colonne_existe_deja_ne_plante_pas(): void
    {
        foreach (self::MIGRATIONS as $colonne => $fichier) {
            $this->assertTrue(Schema::hasColumn('encaissements_ventes', $colonne));

            $this->migration($fichier)->up();

            $this->assertTrue(Schema::hasColumn('encaissements_ventes', $colonne));
        }
    }

    public function test_up_recree_la_colonne_quand_elle_manque(): void
    {
        foreach (self::MIGRATIONS as $colonne => $fichier) {
            $migration = $this->migration($fichier);
            $migration->down();
            $this->assertFalse(Schema::hasColumn('encaissements_ventes', $colonne));

            $migration->up();

            $this->assertTrue(Schema::hasColumn('encaissements_ventes', $colonne));
        }
    }

    public function test_down_est_sans_effet_quand_la_colonne_est_deja_absente(): void
    {
        foreach (self::MIGRATIONS as $colonne => $fichier) {
            $migration = $this->migration($fichier);
            $migration->down();

            $migration->down();

            $this->assertFalse(Schema::hasColumn('encaissements_ventes', $colonne));
        }
    }

    private function migration(string $fichier): Migration
    {
        return require database_path('migrations/'.$fichier);
    }
}
