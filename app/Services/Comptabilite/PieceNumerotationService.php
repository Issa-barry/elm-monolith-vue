<?php

namespace App\Services\Comptabilite;

use App\Models\ExerciceComptable;
use App\Models\JournalComptable;
use Illuminate\Support\Facades\DB;

/**
 * Numérotation séquentielle sans trou, sûre en écriture concurrente.
 *
 * Volontairement PAS un MAX(numero)+1 sur compta_pieces (course possible
 * entre deux workers). La ligne de compteur est verrouillée (SELECT ... FOR
 * UPDATE) DANS la transaction appelante — cette méthode doit donc toujours
 * être invoquée à l'intérieur d'un DB::transaction() ouvert par l'appelant.
 */
class PieceNumerotationService
{
    public function next(JournalComptable $journal, ExerciceComptable $exercice): string
    {
        $cle = [
            'organization_id' => $journal->organization_id,
            'journal_comptable_id' => $journal->id,
            'exercice_comptable_id' => $exercice->id,
        ];

        DB::table('compta_piece_sequences')->insertOrIgnore($cle + ['dernier_numero' => 0]);

        $sequence = DB::table('compta_piece_sequences')
            ->where($cle)
            ->lockForUpdate()
            ->first();

        $numero = (int) $sequence->dernier_numero + 1;

        DB::table('compta_piece_sequences')->where($cle)->update(['dernier_numero' => $numero]);

        $annee = $exercice->date_debut->format('Y');

        return sprintf('%s-%s-%06d', $journal->code, $annee, $numero);
    }
}
