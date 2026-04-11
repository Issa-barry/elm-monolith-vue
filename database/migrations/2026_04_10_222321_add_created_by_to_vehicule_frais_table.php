<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vehicule_frais', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->after('commentaire');
        });
    }

    public function down(): void
    {
        Schema::table('vehicule_frais', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\User::class, 'created_by');
            $table->dropColumn('created_by');
        });
    }
};
