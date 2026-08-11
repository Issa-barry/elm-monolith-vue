<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Force un utilisateur à définir son propre mot de passe à sa première connexion — utilisé par
 * `php artisan app:install` (InstallApp) pour le compte super_admin créé en déploiement : un
 * mot de passe saisi en masqué au terminal reste un secret partagé (visible par l'opérateur qui
 * a lancé la commande) tant que le titulaire réel n'en a pas choisi un lui-même.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
