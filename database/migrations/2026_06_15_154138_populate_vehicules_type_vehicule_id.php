<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $vehicules = DB::table('vehicules')
            ->whereNull('type_vehicule_id')
            ->whereNotNull('type_vehicule')
            ->whereNull('deleted_at')
            ->select('id', 'organization_id', 'type_vehicule')
            ->get();

        foreach ($vehicules as $v) {
            $type = DB::table('type_vehicules')
                ->where('organization_id', $v->organization_id)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(nom) = ?', [mb_strtolower($v->type_vehicule)])
                ->value('id');

            if ($type) {
                DB::table('vehicules')
                    ->where('id', $v->id)
                    ->update(['type_vehicule_id' => $type]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
