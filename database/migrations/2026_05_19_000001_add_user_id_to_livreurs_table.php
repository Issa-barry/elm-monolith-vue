<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('livreurs', function (Blueprint $table) {
            $table->foreignUlid('user_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('livreurs', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\User::class);
            $table->dropColumn('user_id');
        });
    }
};
