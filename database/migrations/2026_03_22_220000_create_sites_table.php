<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('nom', 255);
            $table->string('code', 50);
            $table->string('type', 30);
            $table->string('statut', 30)->default('active');
            $table->string('localisation', 255)->nullable();
            $table->string('pays', 100)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('quartier', 100)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['code', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
