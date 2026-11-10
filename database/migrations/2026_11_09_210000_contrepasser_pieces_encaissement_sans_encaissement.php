<?php

use App\Services\Comptabilite\PiecesEncaissementSansEncaissementService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Régularisation de l'incident VTE-081126-134 (08/11/2026, ADR 0062) : contrepassation, par le service
 * comptable officiel, des pièces de caisse dont l'encaissement a été annulé par la base. Seules les
 * pièces confirmées sans argent reçu sont touchées (cf. PiecesEncaissementSansEncaissementService) ;
 * les autres restent « à examiner ». Rejouable sans double contrepassation. Rapport dans le journal.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bilan = app(PiecesEncaissementSansEncaissementService::class)->regulariser();

        foreach ($bilan['contrepassees'] as $c) {
            Log::info("Incident 08/11 : {$c['numero']} contrepassée par {$c['contrepassation']}", $c);
        }
        foreach ($bilan['a_examiner'] as $c) {
            Log::warning("Incident 08/11 : {$c['numero']} à examiner, non régularisée", $c);
        }
        foreach ($bilan['echecs'] as $c) {
            Log::error("Incident 08/11 : {$c['numero']} non régularisée", $c);
        }
    }

    public function down(): void
    {
        // Une contrepassation ne se défait jamais (règle #29) : rien à annuler.
    }
};
