<?php

use Illuminate\Database\Migrations\Migration;

// Supprimée : commission_active et commission_on_order_creation ont été retirés du modèle Vehicule.
// La commission est désormais toujours générée à la création de commande.
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
