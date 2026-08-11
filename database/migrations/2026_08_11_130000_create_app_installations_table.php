<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * État explicite et global de "l'application a été installée" — délibérément distinct de
 * "une organisation existe" (une organisation peut exister pour d'autres raisons dans une
 * appli multi-tenant : démo, org créée manuellement...). Table singleton : une seule ligne,
 * insérée uniquement par InstallationService::install() une fois tout le reste créé avec
 * succès — jamais en cache (survit à `optimize:clear`, contrairement à un flag en cache).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_installations');
    }
};
