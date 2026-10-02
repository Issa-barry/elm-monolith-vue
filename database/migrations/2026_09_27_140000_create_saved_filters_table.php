<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_filters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 80);
            $table->string('name', 80);
            $table->string('visibility', 16)->default('personal');
            $table->json('filters');
            $table->timestamps();
            $table->unique(['organization_id', 'user_id', 'scope', 'name'], 'saved_filters_owner_scope_name_unique');
        });
        Schema::create('saved_filter_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 80);
            $table->foreignUlid('saved_filter_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['organization_id', 'user_id', 'scope'], 'saved_filter_preferences_owner_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filter_preferences');
        Schema::dropIfExists('saved_filters');
    }
};
