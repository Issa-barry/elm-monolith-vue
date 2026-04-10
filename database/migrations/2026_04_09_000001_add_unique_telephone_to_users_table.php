<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vérifie si l'index unique existe déjà (migration déjà appliquée partiellement)
        $indexExists = collect(DB::select("SHOW INDEX FROM users WHERE Key_name = 'users_telephone_unique'"))->isNotEmpty();

        if ($indexExists) {
            return;
        }

        // En production, des doublons NULL peuvent exister (telephone nullable).
        // MySQL autorise plusieurs NULL dans un index UNIQUE → pas de problème.
        // Pour les doublons non-NULL éventuels, on garde le compte le plus récent.
        DB::statement("
            DELETE u1
            FROM users u1
            INNER JOIN users u2
                ON  u1.telephone IS NOT NULL
                AND u1.telephone  = u2.telephone
                AND u1.id         < u2.id
        ");

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
