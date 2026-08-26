<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Préférences de notification métier (ex: "alertes d'activité"), distinctes de
 * `expo_push_token` (jeton technique de l'appareil pour le push, déjà présent) —
 * ici, la décision de l'utilisateur de recevoir ou non une catégorie d'alerte.
 * JSON nullable plutôt qu'une table dédiée : une seule catégorie existe
 * aujourd'hui (cf. User::NOTIFICATION_PREFERENCE_KEYS), une colonne simple
 * suffit et reste extensible sans migration à chaque nouvelle catégorie —
 * passer à une table dédiée le jour où un besoin réel l'exige (historique,
 * préférences par organisation, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('expo_push_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
