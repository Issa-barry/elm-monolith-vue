<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Nullable (contrairement à audit_logs) : un OTP de vérification
            // téléphone pendant une INSCRIPTION peut être tenté avant que le
            // compte/l'organisation n'existe — cf. App\Services\Communications\
            // MessageLogService::logSmsOtpAttempt(), qui résout l'organisation
            // par recherche du numéro plutôt que de la forcer.
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->string('channel', 20);
            $table->string('direction', 20)->default('outbound');
            $table->string('purpose', 40)->nullable();
            $table->string('provider', 40);
            $table->string('provider_message_id')->nullable();
            $table->string('masked_recipient');

            $table->string('status', 20);
            $table->string('provider_status', 20)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->string('error_message', 500)->nullable();

            // Rattachement métier optionnel — jamais forcé quand le flux OTP
            // n'a pas d'entité naturelle à ce niveau (cf. rapport, point 6).
            $table->nullableUlidMorphs('messageable');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at'], 'message_logs_org_timeline');
            $table->index(['channel', 'status'], 'message_logs_channel_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_logs');
    }
};
