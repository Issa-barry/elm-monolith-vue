<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le taux du propriétaire est désormais géré au niveau de l'équipe de livraison
 * (equipes_livraison.taux_commission_proprietaire), plus sur le véhicule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropColumn('taux_commission_proprietaire');
        });
    }

    public function down(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->decimal('taux_commission_proprietaire', 5, 2)->default(0)->after('proprietaire_id');
        });
    }
};
