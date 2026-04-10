<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MODIFY COLUMN ENUM n'est supporté que sur MySQL/MariaDB.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE cashback_transactions MODIFY COLUMN statut ENUM('en_attente','valide','verse') NOT NULL DEFAULT 'en_attente'");
        }

        Schema::table('cashback_transactions', function (Blueprint $table) {
            $table->timestamp('valide_le')->nullable()->after('verse_par');
            $table->foreignId('valide_par')->nullable()->after('valide_le')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cashback_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('valide_par');
            $table->dropColumn('valide_le');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE cashback_transactions MODIFY COLUMN statut ENUM('en_attente','verse') NOT NULL DEFAULT 'en_attente'");
        }
    }
};
