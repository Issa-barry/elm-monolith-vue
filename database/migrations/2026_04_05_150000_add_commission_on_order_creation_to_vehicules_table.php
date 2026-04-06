<?php

use Illuminate\Database\Migrations\Migration;

// Supprimée : commission_active et commission_on_order_creation ont été retirés du modèle Vehicule.
// La commission est désormais toujours générée à la création de commande.
return new class extends Migration
{
    public function up(): void
    {
        // Intentionally empty:
        // this migration remains as a marker in environments where it was already executed.
        // The related columns were removed before merge, so no schema change is required.
    }

    public function down(): void
    {
        // Intentionally empty for the same reason as up():
        // no schema operation must be reversed here.
    }
};
