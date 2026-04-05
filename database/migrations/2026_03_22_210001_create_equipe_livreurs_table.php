<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipe_livreurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipe_id')->constrained('equipes_livraison')->cascadeOnDelete();
            $table->foreignId('livreur_id')->constrained('livreurs')->restrictOnDelete();
            $table->string('role', 20)->default('principal'); // principal | assistant
            $table->decimal('taux_commission', 5, 2);         // part de ce membre sur la commission totale
            $table->unsignedSmallInteger('ordre')->default(0); // ordre d'affichage
            $table->timestamps();

            $table->unique(['equipe_id', 'livreur_id']);
            $table->index(['equipe_id', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipe_livreurs');
    }
};
