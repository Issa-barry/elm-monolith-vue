<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prestataire_id')->constrained('prestataires')->cascadeOnDelete();
            $table->string('reference', 50)->nullable()->unique();
            $table->date('date');
            $table->unsignedInteger('nb_rouleaux');
            $table->unsignedBigInteger('prix_par_rouleau')->default(0);
            $table->unsignedBigInteger('montant')->default(0);
            $table->string('statut', 20)->default('impayee')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
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
