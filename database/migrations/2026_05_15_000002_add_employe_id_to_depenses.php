<?php

use App\Models\Employe;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depenses', function (Blueprint $table) {
            $table->foreignUlid('employe_id')->nullable()->constrained('employes')->nullOnDelete()->after('vehicule_id');
        });
    }

    public function down(): void
    {
        Schema::table('depenses', function (Blueprint $table) {
            $table->dropForeignIdFor(Employe::class);
            $table->dropColumn('employe_id');
        });
    }
};
