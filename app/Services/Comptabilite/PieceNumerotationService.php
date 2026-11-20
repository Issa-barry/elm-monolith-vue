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

        // Verrou exclusif d'abord. Un INSERT IGNORE sur une ligne existante y pose un verrou partagé
        // gardé jusqu'à la fin de la transaction : deux numérotations simultanées du même journal
        // tenaient chacune ce verrou puis réclamaient l'exclusif, et MySQL annulait l'une des deux
        // (preuve : SoldeSupportConcurrenceTest). L'insertion ne sert plus qu'à la première pièce
        // du journal dans l'exercice ; deux premières pièces simultanées peuvent encore
        // s'interbloquer (verrou d'intervalle sur la ligne absente). Créer la ligne hors de la
        // transaction n'est pas possible : sa clé étrangère attendrait le verrou que la transaction
        // tient sur l'exercice.
        $sequence = DB::table('compta_piece_sequences')->where($cle)->lockForUpdate()->first();
        if ($sequence === null) {
            DB::table('compta_piece_sequences')->insertOrIgnore($cle + ['dernier_numero' => 0]);
            $sequence = DB::table('compta_piece_sequences')->where($cle)->lockForUpdate()->first();
        }

        $numero = (int) $sequence->dernier_numero + 1;

        DB::table('compta_piece_sequences')->where($cle)->update(['dernier_numero' => $numero]);

        $annee = $exercice->date_debut->format('Y');

        return sprintf('%s-%s-%06d', $journal->code, $annee, $numero);
    }
}
