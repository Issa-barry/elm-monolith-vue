<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions_logistiques', function (Blueprint $table) {
            $table->decimal('montant_verse', 12, 2)->default(0)->after('montant_total');
            $table->index(['organization_id', 'statut', 'created_at'], 'comm_log_org_statut_date');
        });
    }

    public function down(): void
    {
        Schema::table('commissions_logistiques', function (Blueprint $table) {
            $table->dropIndex('comm_log_org_statut_date');
            $table->dropColumn('montant_verse');
        });
    }
};
