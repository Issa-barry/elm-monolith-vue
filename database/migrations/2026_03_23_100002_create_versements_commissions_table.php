<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versements_commissions', function (Blueprint $table) {
            $table->id();
            // Lié à une part spécifique (plus de champ texte bénéficiaire)
            $table->foreignId('commission_part_id')->constrained('commission_parts')->cascadeOnDelete();
            $table->decimal('montant', 15, 2);
            $table->date('date_versement');
            $table->string('mode_paiement')->default('especes');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('commission_part_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versements_commissions');
    }
};
