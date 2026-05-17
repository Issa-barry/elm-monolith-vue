<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paie_periodes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('mois')->unsigned(); // 1-12
            $table->smallInteger('annee')->unsigned(); // 2020-2099
            $table->string('statut', 20)->default('brouillon'); // brouillon|calcule|valide_rh|paye|cloture
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'mois', 'annee'], 'paie_periodes_org_mois_annee_unique');
            $table->index(['organization_id', 'statut']);
            $table->index(['organization_id', 'annee', 'mois']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paie_periodes');
    }
};
