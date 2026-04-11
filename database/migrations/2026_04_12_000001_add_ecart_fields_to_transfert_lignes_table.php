<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfert_lignes', function (Blueprint $table) {
            $table->string('ecart_type', 30)->nullable()->after('quantite_recue');
            $table->text('ecart_motif')->nullable()->after('ecart_type');
        });
    }

    public function down(): void
    {
        Schema::table('transfert_lignes', function (Blueprint $table) {
            $table->dropColumn(['ecart_type', 'ecart_motif']);
        });
    }
};
