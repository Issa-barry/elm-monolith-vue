<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('prestataire_id')->constrained('prestataires')->cascadeOnDelete();
            $table->string('reference', 50)->nullable()->unique();
            $table->date('date');
            $table->string('shift', 10)->default('jour');
            $table->unsignedInteger('nb_rouleaux');
            $table->unsignedBigInteger('prix_par_rouleau')->default(0);
            $table->unsignedBigInteger('montant')->default(0);
            $table->string('statut', 20)->default('impayee')->index();
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'statut']);
            $table->index(['organization_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packings');
    }
};
