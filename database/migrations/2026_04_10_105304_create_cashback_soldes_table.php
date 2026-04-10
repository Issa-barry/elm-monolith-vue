<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashback_soldes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // Montant cumulé depuis le dernier gain (remis à 0 à chaque franchissement)
            $table->unsignedBigInteger('cumul_achats')->default(0);

            // Cashback dû au client mais pas encore versé
            $table->unsignedBigInteger('cashback_en_attente')->default(0);

            // Statistiques totales (ne redescendent jamais)
            $table->unsignedBigInteger('total_cashback_gagne')->default(0);
            $table->unsignedBigInteger('total_cashback_verse')->default(0);

            $table->timestamps();

            // Un seul solde par client et par organisation
            $table->unique(['organization_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_soldes');
    }
};
