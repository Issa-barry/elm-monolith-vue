<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Date métier de l'ajustement (saisie par l'utilisateur dans la modale « Ajuster le stock »),
 * distincte de created_at (horodatage technique de création, jamais modifiable). Les mouvements
 * automatiques (vente, transfert, réception) reçoivent la date du jour par défaut via
 * MouvementStockService::appliquer() — seul l'ajustement manuel permet à l'utilisateur de la
 * choisir. Colonne nullable (ce projet n'a pas doctrine/dbal, cf. convention de
 * 2026_08_25_100000_make_created_by_nullable_on_mouvements_stock_table.php pour un ->change() —
 * ici non nécessaire puisqu'il s'agit d'un ajout, pas d'une modification de colonne existante) :
 * l'absence de contrainte NOT NULL en base est compensée par le service, qui la renseigne
 * systématiquement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->date('date')->nullable()->after('quantite');
        });

        // Compatibilité : les mouvements existants n'ont pas de date métier saisie — on la
        // déduit de leur created_at plutôt que de laisser un historique à moitié daté.
        DB::table('mouvements_stock')->whereNull('date')->update([
            'date' => DB::raw('DATE(created_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->dropColumn('date');
        });
    }
};
