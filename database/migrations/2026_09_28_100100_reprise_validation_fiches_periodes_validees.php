<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0010, lot 1 — reprise non destructive : les fiches des périodes déjà validées ou
 * clôturées reçoivent la date (et l'auteur) de validation de leur période. Aucun paiement,
 * aucune écriture comptable, aucune dette ni aucun statut n'est modifié : seules les colonnes
 * validated_at / validated_by, jusqu'ici vides, sont renseignées. Rejouable sans effet.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('paiement_periodes')
            ->whereIn('statut', ['validee', 'cloturee'])
            ->orderBy('id')
            ->select(['id', 'validated_at', 'validated_by', 'updated_at'])
            ->chunk(200, function ($periodes) {
                foreach ($periodes as $periode) {
                    DB::table('paiement_fiches')
                        ->where('periode_id', $periode->id)
                        ->whereNull('validated_at')
                        ->update([
                            'validated_at' => $periode->validated_at ?? $periode->updated_at,
                            'validated_by' => $periode->validated_by,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Rien à défaire : la migration de structure supprime ces colonnes à son propre retour.
    }
};
