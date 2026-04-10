<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->decimal('frais_proprietaire_montant', 10, 2)->default(0)->after('taux_commission_proprietaire');
            $table->string('frais_proprietaire_type', 30)->nullable()->after('frais_proprietaire_montant');
            $table->string('frais_proprietaire_commentaire', 255)->nullable()->after('frais_proprietaire_type');
        });
    }

    public function down(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropColumn([
                'frais_proprietaire_montant',
                'frais_proprietaire_type',
                'frais_proprietaire_commentaire',
            ]);
        });
    }
};
