<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('versements_commission_logistique', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('commission_logistique_part_id', 26);
            $table->foreign('commission_logistique_part_id', 'vcl_clp_id_fk')
                ->references('id')
                ->on('commission_logistique_parts')
                ->cascadeOnDelete();
            $table->decimal('montant', 12, 2);
            $table->date('date_versement');
            $table->string('mode_paiement')->default('especes');
            $table->text('note')->nullable();
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versements_commission_logistique');
    }
};
