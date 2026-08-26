<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace, pour une part générée sur la cible équipe_livraison, le montant GNF
 * fixe par unité réellement appliqué au membre au moment de la génération —
 * pendant de taux_repartition_snapshot (%), qui reste inchangé et continue de
 * servir aux cibles/flux hors périmètre (cf. CommissionGroupeMembre). Les deux
 * colonnes sont mutuellement exclusives : une part n'alimente jamais les deux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_enveloppe_parts', function (Blueprint $table) {
            $table->unsignedInteger('montant_unitaire_snapshot')->nullable()->after('taux_repartition_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('commission_enveloppe_parts', function (Blueprint $table) {
            $table->dropColumn('montant_unitaire_snapshot');
        });
    }
};
