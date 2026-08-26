<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestataires', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 10)->nullable()->unique();
            // Identité (physique ou morale) jamais dupliquée ici — portée par Personne ou
            // EntrepriseTierce, cf. ..._z_create_personnes_table.php et
            // ..._create_entreprises_tierces_table.php. Exactement l'une des deux est renseignée.
            $table->foreignUlid('personne_id')->nullable()->constrained('personnes')->nullOnDelete();
            $table->foreignUlid('entreprise_tierce_id')->nullable()->constrained('entreprises_tierces')->nullOnDelete();
            $table->string('type', 30)->index();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestataires');
    }
};
