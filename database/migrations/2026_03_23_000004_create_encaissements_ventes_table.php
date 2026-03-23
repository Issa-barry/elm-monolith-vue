<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encaissements_ventes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facture_vente_id')->constrained('factures_ventes')->cascadeOnDelete();
            $table->decimal('montant', 12, 2);
            $table->date('date_encaissement');
            $table->string('mode_paiement', 30)->default('especes');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encaissements_ventes');
    }
};
