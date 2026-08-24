<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * produits.qte_stock est un cache dénormalisé resynchronisé depuis la somme des variante_stocks
 * (cf. Produit::resynchroniserQteStock()) — cette somme peut désormais être négative dès qu'une
 * seule variante/site l'est (cf. migration ...100004). Sans ce changement, la resynchronisation
 * échouerait en base (colonne encore UNSIGNED) juste après un mouvement de vente pourtant déjà
 * appliqué sur variante_stocks, laissant les deux tables incohérentes.
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

        DB::statement('ALTER TABLE produits MODIFY qte_stock INT NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE produits MODIFY qte_stock INT UNSIGNED NOT NULL DEFAULT 0');
    }
};
