<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('site_id')->nullable();
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->string('nom_vehicule', 100);
            $table->string('marque', 100)->nullable();
            $table->string('modele', 100)->nullable();
            $table->string('immatriculation', 20);
            $table->foreignUlid('type_vehicule_id')->nullable()->constrained('type_vehicules')->restrictOnDelete();
            $table->string('categorie', 20)->default('interne');
            $table->integer('capacite_packs')->nullable();
            $table->foreignUlid('proprietaire_id')->nullable()->constrained('proprietaires')->restrictOnDelete();
            $table->boolean('pris_en_charge_par_usine')->default(false);
            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['immatriculation', 'organization_id']);
        });

        Schema::table('equipes_livraison', function (Blueprint $table) {
            $table->foreign('vehicule_id')->references('id')->on('vehicules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipes_livraison', function (Blueprint $table) {
            $table->dropForeign(['vehicule_id']);
        });
        Schema::dropIfExists('vehicules');
    }
};
