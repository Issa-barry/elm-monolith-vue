<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des barèmes (Paramètres → Commissions, COMM-021) : l'auteur d'un AJOUT ou d'une
 * MODIFICATION est déjà connu (`created_by` de la nouvelle version) ; celui d'un RETRAIT (version
 * close sans successeur) ne l'était pas. Les retraits antérieurs restent sans auteur (`null`),
 * jamais déduit après coup.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('commission_regles', 'closed_by')) {
            return;
        }

        Schema::table('commission_regles', function (Blueprint $table) {
            $table->foreignUlid('closed_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('commission_regles', 'closed_by')) {
            return;
        }

        Schema::table('commission_regles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
        });
    }
};
