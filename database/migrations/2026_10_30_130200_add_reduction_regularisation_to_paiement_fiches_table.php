<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Régularisation d'une vente après encaissement (ADR 0043) : part du montant d'une fiche qui n'est
 * plus due parce qu'une commission qu'elle porte a été recalculée à la baisse. Les montants figés de
 * la fiche (ADR 0010) restent intacts ; le reste à payer en est déduit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('paiement_fiches', 'reduction_regularisation')) {
            Schema::table('paiement_fiches', function (Blueprint $table) {
                $table->decimal('reduction_regularisation', 15, 2)->default(0)->after('montant_paye');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('paiement_fiches', 'reduction_regularisation')) {
            Schema::table('paiement_fiches', function (Blueprint $table) {
                $table->dropColumn('reduction_regularisation');
            });
        }
    }
};
