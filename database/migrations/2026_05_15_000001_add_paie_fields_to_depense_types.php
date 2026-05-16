<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depense_types', function (Blueprint $table) {
            $table->boolean('applique_aux_employes')->default(false)->after('requires_comment');
            // prime | autre_gain | avance | retenue | absence | autre_deduction
            $table->string('type_paie')->nullable()->after('applique_aux_employes');
        });
    }

    public function down(): void
    {
        Schema::table('depense_types', function (Blueprint $table) {
            $table->dropColumn(['applique_aux_employes', 'type_paie']);
        });
    }
};
