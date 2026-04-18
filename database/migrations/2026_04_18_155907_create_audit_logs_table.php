<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Polymorphic target
            $table->string('auditable_type', 150);
            $table->unsignedBigInteger('auditable_id');

            $table->string('event_code', 50);
            $table->string('event_label', 150);

            // Actor — nullable so system actions (CLI/job) are supported
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name_snapshot', 200)->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('meta')->nullable();

            // Immutable — no updated_at
            $table->timestamp('created_at')->useCurrent();

            // Composite index for "show history of entity X ordered by time"
            $table->index(['organization_id', 'auditable_type', 'auditable_id', 'created_at'], 'audit_entity_timeline');
            $table->index('event_code', 'audit_event_code');
            $table->index('actor_id', 'audit_actor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
