<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Calcule et persiste la période pour chaque part existante.
        // Livreur  → P1 (jours 1-15) ou P2 (jours 16-fin)
        // Autres   → M (mensuel — réservé aux propriétaires)
        DB::table('commission_logistique_parts')
            ->whereNotNull('earned_at')
            ->orderBy('id')
            ->each(function ($row): void {
                $date = Carbon::parse($row->earned_at);

                $periode = match ($row->type_beneficiaire) {
                    'livreur' => $date->day <= 15
                        ? $date->format('Y-m').'-P1'
                        : $date->format('Y-m').'-P2',
                    default => $date->format('Y-m').'-M',
                };

                DB::table('commission_logistique_parts')
                    ->where('id', $row->id)
                    ->update(['periode' => $periode]);
            });
    }

    public function down(): void
    {
        DB::table('commission_logistique_parts')->update(['periode' => null]);
    }
};
