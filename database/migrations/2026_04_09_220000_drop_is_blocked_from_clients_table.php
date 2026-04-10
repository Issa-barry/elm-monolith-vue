<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression du champ is_blocked : le statut est simplifié à is_active uniquement.
 * is_active = false équivaut à un client bloqué/inactif métier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('is_blocked');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_blocked')->default(false)->after('is_active');
        });
    }
};
