<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heure réelle de l'envoi et de la réception d'un mouvement de fonds (ADR 0018) : `date_envoi` et
 * `date_reception` ne portent qu'un jour. Ajout seul, aucune reprise : les mouvements antérieurs
 * gardent leur date sans heure (colonnes nulles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_fonds', function (Blueprint $table) {
            if (! Schema::hasColumn('mouvements_fonds', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('date_reception');
            }
            if (! Schema::hasColumn('mouvements_fonds', 'received_at')) {
                $table->timestamp('received_at')->nullable()->after('sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_fonds', function (Blueprint $table) {
            if (Schema::hasColumn('mouvements_fonds', 'received_at')) {
                $table->dropColumn('received_at');
            }
            if (Schema::hasColumn('mouvements_fonds', 'sent_at')) {
                $table->dropColumn('sent_at');
            }
        });
    }
};
