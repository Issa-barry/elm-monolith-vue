<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE cashback_transactions MODIFY COLUMN statut ENUM('en_attente','valide','partiel','verse') NOT NULL DEFAULT 'en_attente'");
        }

        Schema::table('cashback_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('montant_verse')->default(0)->after('montant');
            // mode_paiement et date_versement migrent vers cashback_versements
            $table->dropColumn(['mode_paiement', 'date_versement']);
        });
    }

    public function down(): void
    {
        Schema::table('cashback_transactions', function (Blueprint $table) {
            $table->dropColumn('montant_verse');
            if (DB::getDriverName() !== 'sqlite') {
                $table->string('mode_paiement')->nullable()->after('verse_le');
                $table->date('date_versement')->nullable()->after('mode_paiement');
            }
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE cashback_transactions MODIFY COLUMN statut ENUM('en_attente','valide','verse') NOT NULL DEFAULT 'en_attente'");
        }
    }
};
