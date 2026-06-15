<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // rejete → annule (rejected by validator = cancelled from user's perspective)
        // impute → valide (imputed was a sub-state of validated; merge back)
        DB::table('depenses')
            ->where('statut', 'rejete')
            ->update(['statut' => 'annule']);

        DB::table('depenses')
            ->where('statut', 'impute')
            ->update(['statut' => 'valide']);
    }

    public function down(): void
    {
        // no safe reversal — values were merged
    }
};
