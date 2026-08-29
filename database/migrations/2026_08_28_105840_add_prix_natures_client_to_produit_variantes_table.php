<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tarification par nature de client (Externe/Revendeur/Distributeur), réservée aux produits de
 * type "fabricable" (code stable, cf. ProduitTypeDefaultSeeder) — indépendante de prix_usine et
 * prix_vente, cf. PrixVenteNatureResolver. NULL = pas de tarif spécifique, repli sur prix_vente
 * à la vente.
 *
 * prix_externe est initialisé à l'ancien prix_usine des variantes fabricables existantes pour
 * préserver le comportement tarifaire déjà en place pour les clients Externes (qui facturaient
 * jusqu'ici au prix usine sur tout produit) — ajustable indépendamment ensuite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produit_variantes', function (Blueprint $table) {
            $table->unsignedBigInteger('prix_externe')->nullable()->after('prix_usine_tricycle');
            $table->unsignedBigInteger('prix_revendeur')->nullable()->after('prix_externe');
            $table->unsignedBigInteger('prix_distributeur')->nullable()->after('prix_revendeur');
        });

        DB::table('produit_variantes as pv')
            ->join('produits as p', 'p.id', '=', 'pv.produit_id')
            ->join('produit_types as pt', 'pt.id', '=', 'p.produit_type_id')
            ->where('pt.code', 'fabricable')
            ->whereNotNull('pv.prix_usine')
            ->update(['pv.prix_externe' => DB::raw('pv.prix_usine')]);
    }

    public function down(): void
    {
        Schema::table('produit_variantes', function (Blueprint $table) {
            $table->dropColumn(['prix_externe', 'prix_revendeur', 'prix_distributeur']);
        });
    }
};
