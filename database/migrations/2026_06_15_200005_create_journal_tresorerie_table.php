<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_tresorerie', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->date('date_operation');
            $table->string('sens', 10);
            $table->string('categorie', 30);
            $table->string('libelle', 255);
            $table->string('reference', 50)->nullable();
            $table->decimal('montant', 15, 2);
            $table->string('source_type', 100)->nullable();
            $table->string('source_id', 26)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'sens', 'date_operation']);
            $table->index(['organization_id', 'categorie']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_tresorerie');
    }
};
