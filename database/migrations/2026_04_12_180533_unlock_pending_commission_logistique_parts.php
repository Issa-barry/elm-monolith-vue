<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Les commissions logistique n'ont pas de periode de blocage :
        // on passe toutes les parts PENDING en AVAILABLE.
        DB::table('commission_logistique_parts')
            ->where('statut', 'pending')
            ->update([
                'statut'    => 'available',
                'unlock_at' => DB::raw('earned_at'),
            ]);
    }

    public function down(): void
    {
        // Pas de retour arriere utile.
    }
};
