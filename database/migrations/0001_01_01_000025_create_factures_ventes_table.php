<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures_ventes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('numero')->nullable()->index();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignUlid('vehicule_id')->nullable()->constrained('vehicules')->nullOnDelete();
            $table->foreignUlid('commande_vente_id')->constrained('commandes_ventes')->cascadeOnDelete();
            $table->string('reference', 20)->unique();
            $table->char('code_confirmation', 3)->nullable();
            $table->decimal('montant_brut', 12, 2);
            $table->decimal('montant_net', 12, 2);
            $table->string('statut_facture', 30)->default('impayee');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factures_ventes');
    }
};
