<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicule_frais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicule_id')->constrained('vehicules')->cascadeOnDelete();
            $table->decimal('montant', 10, 2);
            $table->string('type', 30); // carburant | reparation | autre
            $table->string('commentaire', 150)->nullable();
            $table->timestamps();
        });

        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropColumn([
                'frais_proprietaire_montant',
                'frais_proprietaire_type',
                'frais_proprietaire_commentaire',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicule_frais');

        Schema::table('vehicules', function (Blueprint $table) {
            $table->decimal('frais_proprietaire_montant', 10, 2)->default(0)->after('taux_commission_proprietaire');
            $table->string('frais_proprietaire_type', 30)->nullable()->after('frais_proprietaire_montant');
            $table->string('frais_proprietaire_commentaire', 255)->nullable()->after('frais_proprietaire_type');
        });
    }
};
