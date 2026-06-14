<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Les enregistrements 'annule' avec un motif_rejet sont des rejets admin,
        // pas des annulations volontaires. On les recorrige en 'rejete'.
        DB::table('depenses')
            ->where('statut', 'annule')
            ->whereNotNull('motif_rejet')
            ->update(['statut' => 'rejete']);
    }

    public function down(): void
    {
        DB::table('depenses')
            ->where('statut', 'rejete')
            ->update(['statut' => 'annule']);
    }
};
