<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CommandeNumeroService
{
    /**
     * Génère un numéro de commande atomique au format CMD-JJMMAA-XXX.
     *
     * Le compteur est mensuel (repart à 001 chaque mois) et thread-safe
     * grâce à un verrou SELECT ... FOR UPDATE sur la table commande_sequences.
     *
     * @return array{0: string, 1: int} [$reference, $numero]
     *
     * @throws \OverflowException si la limite de 999 commandes/mois est atteinte
     */
    public function generer(): array
    {
        return DB::transaction(function () {
            $now = now();
            $periode = $now->format('Y-m');

            DB::table('commande_sequences')
                ->insertOrIgnore(['periode' => $periode, 'compteur' => 0]);

            $compteur = DB::table('commande_sequences')
                ->where('periode', $periode)
                ->lockForUpdate()
                ->value('compteur');

            $prochain = (int) $compteur + 1;

            if ($prochain > 999) {
                throw new \OverflowException(
                    "La limite mensuelle de 999 commandes est atteinte pour la période {$periode}."
                );
            }

            DB::table('commande_sequences')
                ->where('periode', $periode)
                ->update(['compteur' => $prochain]);

            $reference = 'CMD-'.$now->format('dmy').'-'.str_pad((string) $prochain, 3, '0', STR_PAD_LEFT);

            return [$reference, $prochain];
        });
    }
}
