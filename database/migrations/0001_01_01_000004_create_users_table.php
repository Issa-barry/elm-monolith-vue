<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            // Compte d'accès pur — aucune identité civile ici. Un User appartient toujours à
            // une Personne (nom/prenom/pays/adresse) et s'authentifie via une ou plusieurs
            // UserAuthIdentity (telephone/email) — cf. les deux migrations suivantes. Ni
            // `telephone` ni `email` ne reviennent jamais comme colonnes sur `users`.
            $table->foreignUlid('personne_id')->constrained('personnes')->restrictOnDelete();
            $table->string('matricule', 6)->nullable();
            $table->string('expo_push_token', 200)->nullable();
            $table->string('password');
            // Force un utilisateur à définir son propre mot de passe à sa première connexion —
            // utilisé par `php artisan app:install` (InstallApp) pour le compte super_admin créé
            // en déploiement : un mot de passe saisi en masqué au terminal reste un secret
            // partagé (visible par l'opérateur qui a lancé la commande) tant que le titulaire
            // réel n'en a pas choisi un lui-même.
            $table->boolean('must_change_password')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('status', 20)->default('active');
            $table->rememberToken();
            $table->timestamps();

            $table->unique(['organization_id', 'matricule'], 'users_org_matricule_unique');
        });

        // Table du PasswordBroker Laravel (Fortify Features::resetPasswords()) — clé `email`
        // en texte libre, jamais une FK vers users.email (qui n'existe plus) : Fortify la
        // remplit via User::getEmailForPasswordReset(), surchargé pour lire l'identité email
        // dans user_auth_identities (cf. App\Models\User).
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->char('user_id', 26)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
