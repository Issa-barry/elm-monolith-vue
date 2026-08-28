<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonne additive (cf. rapport du 27/08/2026, chantier OTP agnostique du
 * canal) : enregistre PAR QUEL canal une identité a été vérifiée (email, sms,
 * whatsapp), toujours renseignée EN MÊME TEMPS que `verified_at`, jamais
 * l'une sans l'autre — cf. `UserAuthIdentity::markVerifiedVia()`, seul point
 * qui écrit ces deux colonnes.
 *
 * Nullable pour les lignes déjà vérifiées avant cette migration : leur canal
 * historique n'est pas déductible avec certitude à partir des données
 * existantes (aucune trace du canal n'était conservée) — `verification_channel
 * = NULL` reste un état valide pour une identité déjà `verified_at IS NOT
 * NULL`, jamais une provenance inventée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_auth_identities', function (Blueprint $table) {
            $table->string('verification_channel', 20)->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_auth_identities', function (Blueprint $table) {
            $table->dropColumn('verification_channel');
        });
    }
};
