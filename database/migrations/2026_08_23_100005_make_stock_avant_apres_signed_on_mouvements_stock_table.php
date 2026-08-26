<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pendant qu'un mouvement de sortie fait passer le stock sous 0 (produit autorisant la vente
 * sans stock, cf. migration ...100003), stock_avant/stock_apres doivent pouvoir enregistrer une
 * valeur négative — sinon le journal redeviendrait mathématiquement faux exactement comme le
 * clamp silencieux qu'on supprime (cf. MouvementStockService::appliquer()).
 *
 * SQL brut (pas ->change()) : ce projet n'a pas doctrine/dbal installé, même convention que
 * 2026_08_17_000004_make_capacite_defaut_nullable_on_type_vehicules_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (suite de tests) n'a pas d'ALTER COLUMN et n'applique de toute façon jamais la
        // contrainte UNSIGNED (typage dynamique) : une valeur négative y est déjà acceptée sans
        // cette migration. Sans effet pratique sur ce driver.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE mouvements_stock MODIFY stock_avant INT NULL');
        DB::statement('ALTER TABLE mouvements_stock MODIFY stock_apres INT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE mouvements_stock MODIFY stock_avant INT UNSIGNED NULL');
        DB::statement('ALTER TABLE mouvements_stock MODIFY stock_apres INT UNSIGNED NULL');
    }
};
