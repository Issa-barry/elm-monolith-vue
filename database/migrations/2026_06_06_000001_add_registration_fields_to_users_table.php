<?php

use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)
                ->default(UserStatus::ACTIVE->value)
                ->after('is_active');

            $table->string('email_verification_token', 64)
                ->nullable()
                ->unique()
                ->after('email_verified_at');

            $table->timestamp('email_verification_expires_at')
                ->nullable()
                ->after('email_verification_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'email_verification_token',
                'email_verification_expires_at',
            ]);
        });
    }
};
