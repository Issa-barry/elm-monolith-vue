<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Couche "comment je me connecte", séparée de Personne ("qui je suis") et de User ("à quoi
 * j'ai accès") — cf. database/migrations/0001_01_01_000003_z_create_personnes_table.php.
 *
 * normalized_value porte l'unicité GLOBALE (toute la plateforme, jamais scopée par
 * organisation) : une valeur donnée (numéro E.164, email en minuscules) n'authentifie jamais
 * plus d'un seul User, contrairement à Personne.telephone_normalise qui n'est unique que par
 * organisation. Ce sont deux garanties différentes qui ne doivent jamais être confondues.
 *
 * verification_token/verification_expires_at remplacent les anciennes colonnes
 * users.email_verification_token/email_verification_expires_at : la vérification est
 * maintenant portée par l'identité elle-même (permet, à terme, de vérifier un téléphone comme
 * un email de la même façon).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_auth_identities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('value', 100);
            $table->string('normalized_value', 100);
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_token', 64)->nullable()->unique();
            $table->timestamp('verification_expires_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['type', 'normalized_value']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_auth_identities');
    }
};
