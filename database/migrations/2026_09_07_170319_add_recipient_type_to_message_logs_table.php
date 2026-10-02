<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            // Nullable : uniquement renseigné pour les notifications transactionnelles
            // (cf. App\Services\Communications\TransactionalCommunicationDispatcher) —
            // jamais pour un OTP (App\Services\Communications\MessageLogService::
            // logSmsOtpAttempt() ne le renseigne pas).
            $table->string('recipient_type', 20)->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropColumn('recipient_type');
        });
    }
};
