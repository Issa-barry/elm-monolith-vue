<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livreurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('prenom');
            $table->string('telephone', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Unicité du téléphone par organisation (pas globale multi-org)
            $table->unique(['telephone', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livreurs');
    }
};
