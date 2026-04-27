<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('equipe_livreurs')->where('role', 'principal')->update(['role' => 'chauffeur']);
        DB::table('equipe_livreurs')->where('role', 'assistant')->update(['role' => 'convoyeur']);

        Schema::table('equipe_livreurs', function (Blueprint $table) {
            $table->string('role', 20)->default('chauffeur')->change();
        });
    }

    public function down(): void
    {
        DB::table('equipe_livreurs')->where('role', 'chauffeur')->update(['role' => 'principal']);
        DB::table('equipe_livreurs')->where('role', 'convoyeur')->update(['role' => 'assistant']);

        Schema::table('equipe_livreurs', function (Blueprint $table) {
            $table->string('role', 20)->default('principal')->change();
        });
    }
};
