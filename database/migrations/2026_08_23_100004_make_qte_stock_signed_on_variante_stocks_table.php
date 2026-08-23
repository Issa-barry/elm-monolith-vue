<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le stock peut désormais devenir négatif (produit avec autorise_vente_stock_negatif = true, cf.
 * migration ...100003) : une vente au-delà du disponible applique le delta EN ENTIER plutôt que
 * de le clamper silencieusement à 0 (cf. MouvementStockService::appliquer()) — le stock négatif
 * représente une quantité vendue mais pas encore couverte par le stock physique. Nécessite que
 * qte_stock accepte les valeurs négatives.
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

        DB::statement('ALTER TABLE variante_stocks MODIFY qte_stock INT NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE variante_stocks MODIFY qte_stock INT UNSIGNED NOT NULL DEFAULT 0');
    }
};
