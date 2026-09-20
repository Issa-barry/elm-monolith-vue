<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Issue de la scission d'une migration renommée après déploiement : la colonne peut déjà exister.
        if (Schema::hasColumn('encaissements_ventes', 'operateur_mobile_money')) {
            return;
        }

        Schema::table('encaissements_ventes', function (Blueprint $table) {
            $table->string('operateur_mobile_money', 30)->nullable()->after('mode_paiement');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('encaissements_ventes', 'operateur_mobile_money')) {
            return;
        }

        Schema::table('encaissements_ventes', function (Blueprint $table) {
            $table->dropColumn('operateur_mobile_money');
        });
    }
};
