<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un abonnement Web Push navigateur (endpoint + clés de chiffrement) — pas
 * un token Expo (users.expo_push_token), qui identifie une INSTALLATION APP
 * MOBILE, alors qu'un abonnement Web Push identifie un NAVIGATEUR précis sur
 * un appareil précis. Un User peut en avoir plusieurs (iPhone + Android + PC
 * pro + PC perso) — cf. rapport Web Push, 2026-08-28.
 *
 * `endpoint_hash` unique GLOBALEMENT (pas par user) : un endpoint appartient
 * physiquement à un seul navigateur à la fois. Si un autre User se réabonne
 * depuis ce même navigateur (poste partagé), l'abonnement lui est
 * simplement réassigné (upsert par endpoint_hash côté contrôleur) — jamais
 * un doublon, jamais une erreur d'unicité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            // `endpoint` en text : longueur variable selon le fournisseur push (FCM, Mozilla
            // autopush...), pas de limite fiable à imposer. `endpoint_hash` (sha256) porte seul
            // la contrainte d'unicité — un index unique sur un `text` complet n'est pas fiable
            // sur toutes les bases (limite de taille de clé d'index MySQL notamment).
            $table->text('endpoint');
            $table->string('endpoint_hash', 64);
            $table->text('p256dh');
            $table->text('auth');
            // Nullable : navigateurs modernes n'envoient plus que 'aes128gcm' (repli appliqué à
            // l'envoi si absent, cf. WebPushService) — colonne conservée pour flexibilité future
            // sans jamais forcer le frontend à la renseigner.
            $table->string('content_encoding')->nullable();
            // Dérivé automatiquement de la requête HTTP (User-Agent) au moment de l'abonnement —
            // jamais un champ saisi/choisi par le client (cf. device_name volontairement absent :
            // aucun écran "gérer mes appareils" ne l'exploite encore).
            $table->string('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique('endpoint_hash', 'web_push_subscriptions_endpoint_hash_unique');
            $table->index('user_id', 'web_push_subscriptions_user_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_subscriptions');
    }
};
