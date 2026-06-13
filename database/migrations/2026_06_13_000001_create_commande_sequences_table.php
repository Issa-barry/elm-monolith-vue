<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commande_sequences', function (Blueprint $table) {
            $table->char('periode', 7)->primary(); // format 'YYYY-MM'
            $table->unsignedSmallInteger('compteur')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commande_sequences');
    }
};
