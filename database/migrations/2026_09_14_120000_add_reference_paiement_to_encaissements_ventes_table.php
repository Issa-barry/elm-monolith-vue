<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remplace une migration renommée après déploiement : les bases qui l'ont déjà jouée ont déjà la colonne.
        if (Schema::hasColumn('encaissements_ventes', 'reference_paiement')) {
            return;
        }

        Schema::table('encaissements_ventes', function (Blueprint $table) {
            $table->string('reference_paiement', 190)->nullable()->after('mode_paiement');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('encaissements_ventes', 'reference_paiement')) {
            return;
        }

        Schema::table('encaissements_ventes', function (Blueprint $table) {
            $table->dropColumn('reference_paiement');
        });
    }
};
