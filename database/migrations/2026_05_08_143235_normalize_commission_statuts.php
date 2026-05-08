<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalise tous les statuts de commission vers les trois valeurs unifiées:
 *   impaye | partiel | paye
 *
 * Règle métier:
 *   montant_verse  = 0          → impaye
 *   0 < montant_verse < total   → partiel
 *   montant_verse >= total > 0  → paye
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── commissions_ventes ──────────────────────────────────────────────────
        DB::statement("
            UPDATE commissions_ventes
            SET statut = CASE
                WHEN montant_verse >= montant_commission_totale AND montant_commission_totale > 0
                    THEN 'paye'
                WHEN montant_verse > 0
                    THEN 'partiel'
                ELSE 'impaye'
            END
        ");

        // ── commission_parts (vente) ────────────────────────────────────────────
        DB::statement("
            UPDATE commission_parts
            SET statut = CASE
                WHEN montant_verse >= montant_net AND montant_net > 0
                    THEN 'paye'
                WHEN montant_verse > 0
                    THEN 'partiel'
                ELSE 'impaye'
            END
        ");

        // ── commissions_logistiques ─────────────────────────────────────────────
        DB::statement("
            UPDATE commissions_logistiques
            SET statut = CASE
                WHEN montant_verse >= montant_total AND montant_total > 0
                    THEN 'paye'
                WHEN montant_verse > 0
                    THEN 'partiel'
                ELSE 'impaye'
            END
        ");

        // ── commission_logistique_parts ─────────────────────────────────────────
        DB::statement("
            UPDATE commission_logistique_parts
            SET statut = CASE
                WHEN montant_verse >= montant_net AND montant_net > 0
                    THEN 'paye'
                WHEN montant_verse > 0
                    THEN 'partiel'
                ELSE 'impaye'
            END
        ");
    }

    public function down(): void
    {
        // Réversibilité limitée : on remet tout à 'impaye' comme état neutre.
        DB::statement("UPDATE commissions_ventes SET statut = 'impaye'");
        DB::statement("UPDATE commission_parts SET statut = 'impaye'");
        DB::statement("UPDATE commissions_logistiques SET statut = 'impaye'");
        DB::statement("UPDATE commission_logistique_parts SET statut = 'impaye'");
    }
};
