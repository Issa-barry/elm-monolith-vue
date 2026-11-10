<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel Contacts, lot 1 (ADR 0060) — un interlocuteur porte sa fonction dans l'entreprise,
 * peut être le principal, et se désactive sans perdre son historique. L'unicité du principal actif
 * est garantie par App\Services\Contacts\InterlocuteurService (transaction + verrou sur
 * l'entreprise), pas par un index : aucun index unique partiel portable entre SQLite, MySQL et
 * MariaDB. Colonnes ajoutées sans valeur imposée aux lignes existantes (est_principal = false).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entreprise_interlocuteurs', function (Blueprint $table) {
            $table->string('fonction', 100)->nullable()->after('personne_id');
            $table->boolean('est_principal')->default(false)->after('fonction');
            $table->text('notes')->nullable()->after('is_active');
            $table->timestamp('desactive_le')->nullable()->after('notes');
            $table->foreignUlid('desactive_par')->nullable()->after('desactive_le')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('entreprise_interlocuteurs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('desactive_par');
            $table->dropColumn(['fonction', 'est_principal', 'notes', 'desactive_le']);
        });
    }
};
