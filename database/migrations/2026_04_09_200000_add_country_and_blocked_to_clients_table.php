<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute les champs pays/localisation et is_blocked à la table clients.
 *
 * - pays, code_pays, code_phone_pays : gestion internationale du téléphone
 * - ville                           : localisation du client
 * - is_blocked                      : blocage métier (empêche l'usage dans les flux)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('pays')->nullable()->after('adresse');
            $table->string('code_pays', 5)->nullable()->after('pays');
            $table->string('code_phone_pays', 10)->nullable()->after('code_pays');
            $table->string('ville', 100)->nullable()->after('code_phone_pays');
            $table->boolean('is_blocked')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['pays', 'code_pays', 'code_phone_pays', 'ville', 'is_blocked']);
        });
    }
};
