<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('depense_type_id')->constrained('depense_types')->restrictOnDelete();
            $table->foreignUlid('vehicule_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('montant', 12, 2);
            $table->date('date_depense');
            $table->text('commentaire')->nullable();

            $table->string('statut')->default('brouillon'); // brouillon | soumis | approuve | rejete

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'statut', 'date_depense']);
            $table->index(['organization_id', 'depense_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depenses');
    }
};
