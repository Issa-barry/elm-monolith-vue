<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('nom_vehicule', 100);
            $table->string('marque', 100)->nullable();
            $table->string('modele', 100)->nullable();
            $table->string('immatriculation', 20);
            $table->string('type_vehicule', 30);
            $table->integer('capacite_packs')->nullable();
            $table->foreignId('proprietaire_id')->constrained('proprietaires')->restrictOnDelete();
            $table->foreignId('livreur_principal_id')->nullable()->constrained('livreurs')->nullOnDelete();
            $table->boolean('pris_en_charge_par_usine')->default(false);
            $table->decimal('taux_commission_livreur', 8, 2)->nullable();
            $table->boolean('commission_active')->default(false);
            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['immatriculation', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicules');
    }
};
