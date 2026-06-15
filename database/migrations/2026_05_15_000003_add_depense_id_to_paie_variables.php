<?php

use App\Models\Depense;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paie_variables', function (Blueprint $table) {
            $table->foreignUlid('depense_id')->nullable()->constrained('depenses')->nullOnDelete()->after('paie_ligne_id');
        });
    }

    public function down(): void
    {
        Schema::table('paie_variables', function (Blueprint $table) {
            $table->dropForeignIdFor(Depense::class);
            $table->dropColumn('depense_id');
        });
    }
};
