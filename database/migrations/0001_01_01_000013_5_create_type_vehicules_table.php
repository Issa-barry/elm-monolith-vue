<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('type_vehicules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('nom', 100);
            $table->integer('capacite_defaut');
            $table->string('unite_capacite', 20)->default('packs');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['nom', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('type_vehicules');
    }
};
