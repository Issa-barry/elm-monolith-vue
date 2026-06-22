<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('matricule', 6);
            $table->string('nom', 100);
            $table->string('prenom', 100);
            $table->string('email', 255)->nullable();
            $table->string('telephone', 50)->nullable();
            $table->string('type_employe', 20)->default('interne');
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('statut', 20)->default('actif');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'matricule'], 'employes_org_matricule_unique');
        });

        Schema::table('depenses', function (Blueprint $table) {
            $table->foreign('employe_id')->references('id')->on('employes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('depenses', function (Blueprint $table) {
            $table->dropForeign(['employe_id']);
        });
        Schema::dropIfExists('employes');
    }
};
