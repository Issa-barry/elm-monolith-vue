<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('cle');
            $table->text('valeur')->nullable();
            $table->string('type')->default('string'); // string, integer, boolean, json
            $table->string('groupe')->default('general'); // general, packing
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'cle']);
            $table->index(['organization_id', 'groupe']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametres');
    }
};
