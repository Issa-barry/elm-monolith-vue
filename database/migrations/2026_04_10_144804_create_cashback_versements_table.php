<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashback_versements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashback_transaction_id')->constrained('cashback_transactions')->cascadeOnDelete();
            $table->unsignedBigInteger('montant');
            $table->string('mode_paiement');
            $table->date('date_versement');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_versements');
    }
};
