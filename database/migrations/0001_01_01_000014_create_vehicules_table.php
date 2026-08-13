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
            $table->integer('capacite_packs')->nullable();
            // Nullable, comme capacite_packs : la plupart des véhicules ne transportent
            // pas de bouteilles (cf. type_vehicules.capacite_defaut_bouteilles).
            $table->unsignedInteger('capacite_bouteilles')->nullable();
            $table->foreignUlid('proprietaire_id')->nullable()->constrained('proprietaires')->restrictOnDelete();
            // Toute ligne dans `vehicules` appartient par définition à la flotte gérée : il
            // n'existe plus de notion de "prise en charge" séparée (cf. ex-pris_en_charge_par_usine)
            // ni de "catégorie interne/externe" — ces deux booléens indépendants décrivent
            // uniquement à quoi le véhicule est autorisé (l'un, l'autre, ou les deux). Un véhicule
            // qui n'appartient à aucun des deux workflows appartient à un partenaire hors flotte
            // (cf. ClientVehicle) et n'a pas sa place dans cette table.
            $table->boolean('livraison_vente')->default(true);
            $table->boolean('livraison_logistique')->default(false);
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
