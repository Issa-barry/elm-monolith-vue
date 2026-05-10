<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('propositions_vehicules')->where('statut', 'pending')->update(['statut' => 'soumise']);
        DB::table('propositions_vehicules')->where('statut', 'approved')->update(['statut' => 'convertie']);
        DB::table('propositions_vehicules')->where('statut', 'rejected')->update(['statut' => 'rejetee']);
    }

    public function down(): void
    {
        DB::table('propositions_vehicules')->where('statut', 'soumise')->update(['statut' => 'pending']);
        DB::table('propositions_vehicules')->where('statut', 'convertie')->update(['statut' => 'approved']);
        DB::table('propositions_vehicules')->where('statut', 'rejetee')->update(['statut' => 'rejected']);
        DB::table('propositions_vehicules')->where('statut', 'en_revision')->update(['statut' => 'pending']);
        DB::table('propositions_vehicules')->where('statut', 'a_completer')->update(['statut' => 'pending']);
    }
};
