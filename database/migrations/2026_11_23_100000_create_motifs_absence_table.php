<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel des motifs d'absence, par organisation (docs/rh-absences.md, ADR 0070). Nouvelle table
 * uniquement : aucune donnée existante n'est touchée. Un motif ne se supprime pas, il se désactive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motifs_absence', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('libelle', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motifs_absence');
    }
};
