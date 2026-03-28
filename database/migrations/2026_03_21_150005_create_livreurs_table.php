<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livreurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nom');
            $table->string('prenom');
            $table->string('email')->nullable()->unique();
            $table->string('telephone', 20)->unique();
            $table->text('adresse')->nullable();
            $table->string('ville', 100);
            $table->string('pays', 100);
            $table->string('code_pays', 5);
            $table->string('code_phone_pays', 10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livreurs');
    }
};
