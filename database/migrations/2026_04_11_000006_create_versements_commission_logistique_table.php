<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versements_commission_logistique', function (Blueprint $table) {
            $table->id();
            // Nom de contrainte explicite (auto-généré = 70 chars > limite MySQL 64)
            $table->unsignedBigInteger('commission_logistique_part_id');
            $table->foreign('commission_logistique_part_id', 'vers_comm_log_part_id_fk')
                ->references('id')
                ->on('commission_logistique_parts')
                ->cascadeOnDelete();
            $table->decimal('montant', 12, 2);
            $table->date('date_versement');
            $table->string('mode_paiement')->default('especes'); // especes | virement | cheque | mobile_money
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['commission_logistique_part_id', 'date_versement'], 'vers_comm_log_part_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versements_commission_logistique');
    }
};
