<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compte commun (ADR 0016, lot 0) : un support peut être utilisé par plusieurs agences tout en
 * restant détenu par une seule — `site_id` reste l'agence détentrice (celle qui porte réellement le
 * compte et son solde au grand livre), la table pivot liste les agences qui l'utilisent, détentrice
 * comprise. Les supports existants restent propres à leur agence (`commun` = false) : aucun n'est
 * reclassé ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('compta_supports_tresorerie', 'commun')) {
            Schema::table('compta_supports_tresorerie', function (Blueprint $table) {
                $table->boolean('commun')->default(false)->after('agent_id');
            });
        }

        if (! Schema::hasTable('compta_support_tresorerie_agences')) {
            Schema::create('compta_support_tresorerie_agences', function (Blueprint $table) {
                $table->foreignUlid('compte_tresorerie_id')->constrained('compta_supports_tresorerie')->cascadeOnDelete();
                $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
                $table->timestamps();

                $table->primary(['compte_tresorerie_id', 'site_id']);
                $table->index('site_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compta_support_tresorerie_agences');

        if (Schema::hasColumn('compta_supports_tresorerie', 'commun')) {
            Schema::table('compta_supports_tresorerie', function (Blueprint $table) {
                $table->dropColumn('commun');
            });
        }
    }
};
