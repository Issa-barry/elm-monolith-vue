<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vérification compatible SQLite + MySQL via Schema::getIndexes()
        $alreadyIndexed = collect(Schema::getIndexes('users'))
            ->contains('name', 'users_telephone_unique');

        if ($alreadyIndexed) {
            return;
        }

        // Supprime les doublons non-NULL avec le query builder Laravel (cross-database)
        $duplicateIds = DB::table('users as u1')
            ->join('users as u2', function ($join) {
                $join->on('u1.telephone', '=', 'u2.telephone')
                    ->on('u1.id', '<', 'u2.id');
            })
            ->whereNotNull('u1.telephone')
            ->pluck('u1.id');

        if ($duplicateIds->isNotEmpty()) {
            DB::table('users')->whereIn('id', $duplicateIds)->delete();
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('telephone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telephone']);
        });
    }
};
