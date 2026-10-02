<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('module', 20);
            $table->string('event', 30);
            $table->string('recipient_type', 20);
            // Nullable : obligatoire quand recipient_type=client (App\Enums\ClientType),
            // toujours null quand recipient_type=livreur (cf. App\Models\CommunicationRule).
            $table->string('client_type', 20)->nullable();
            $table->string('channel', 20);
            $table->boolean('enabled')->default(false);

            $table->timestamps();

            // NOTE : MySQL considère chaque NULL comme distinct dans un index UNIQUE —
            // cette contrainte empêche donc bien les doublons pour les règles CLIENT
            // (client_type toujours renseigné), mais PAS pour les règles LIVREUR
            // (client_type toujours NULL, jamais en conflit avec lui-même au niveau
            // moteur). L'absence de doublon livreur est garantie par le point d'écriture
            // unique (App\Http\Controllers\Settings\CommunicationRuleController::update(),
            // toujours un updateOrCreate() jamais un insert brut), pas par cet index seul.
            $table->unique(
                ['organization_id', 'module', 'event', 'recipient_type', 'client_type', 'channel'],
                'communication_rules_unique_rule'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_rules');
    }
};
