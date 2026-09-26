<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remplace, pour les nouvelles références uniquement, l'ancien compteur mensuel
     * `commande_sequences` (partagé entre toutes les organisations, jamais scopé par
     * préfixe) — laissé intact, ses données restent l'historique des références `CMD-...`
     * déjà émises. Cette nouvelle table sert VTE/DST (CommandeVente) et TRF
     * (TransfertLogistique) : une séquence indépendante par organisation + préfixe + jour
     * (cohérente avec le format affiché PREFIXE-JJMMAA-NNN), verrouillée en écriture
     * (SELECT ... FOR UPDATE, cf. App\Services\ReferenceNumeroService) — même principe que
     * compta_piece_sequences.
     */
    public function up(): void
    {
        Schema::create('reference_sequences', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('prefixe', 5);
            $table->char('periode', 6); // format 'JJMMAA', identique au fragment de date de la référence
            $table->unsignedSmallInteger('compteur')->default(0);
            $table->primary(['organization_id', 'prefixe', 'periode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_sequences');
    }
};
