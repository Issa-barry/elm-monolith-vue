<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration locale (Sanctum ne charge pas automatiquement la sienne — voir
 * SanctumServiceProvider::boot(), qui se contente de la rendre publishable).
 * tokenable_id est en string(26) directement : les modèles tokenable (User)
 * utilisent des ULID, pas des bigint auto-increment.
 *
 * Garde hasTable() : sur les environnements déjà déployés avant la
 * consolidation des migrations, la table existe déjà (créée par l'ancienne
 * migration 2026_05_31_000001_fix_personal_access_tokens_tokenable_id,
 * supprimée depuis). Sans cette garde, `migrate` échoue avec "table already
 * exists" car ce nouveau nom de fichier n'est pas encore dans `migrations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->string('tokenable_id', 26);
            $table->index(['tokenable_type', 'tokenable_id']);
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
