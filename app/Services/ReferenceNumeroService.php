<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Remplace CommandeNumeroService (retiré) — généralisé le 31/08/2026 pour servir à la fois
 * CommandeVente (VTE-/DST-) et TransfertLogistique (TRF-). Deux évolutions par rapport à
 * l'ancien générateur, exigées par le chantier "références par processus" :
 *  - le compteur est désormais scopé par organisation (organization_id + prefixe + periode) —
 *    l'ancien compteur commande_sequences était mensuel et PARTAGÉ entre toutes les
 *    organisations, particularité non désirée dans un ERP multi-tenant ;
 *  - le compteur est désormais journalier (periode = JJMMAA), cohérent avec le format affiché
 *    PREFIXE-JJMMAA-NNN — l'ancien compteur était mensuel malgré une référence affichant le jour.
 *
 * Les références déjà émises (CMD-..., TR-...) restent inchangées : cette table
 * (reference_sequences) démarre vide pour chaque nouveau couple organisation/préfixe/jour,
 * aucune migration de compteur historique n'est nécessaire.
 */
class ReferenceNumeroService
{
    /**
     * Génère une référence métier atomique au format PREFIXE-JJMMAA-NNN, thread-safe via un
     * verrou SELECT ... FOR UPDATE sur reference_sequences.
     *
     * @return array{0: string, 1: int} [$reference, $numero]
     *
     * @throws \OverflowException si la limite journalière de 999 références est atteinte pour ce
     *                            couple organisation/préfixe
     */
    public function generer(string $organizationId, string $prefixe): array
    {
        return DB::transaction(function () use ($organizationId, $prefixe) {
            $now = now();
            $periode = $now->format('dmy');
            $cle = ['organization_id' => $organizationId, 'prefixe' => $prefixe, 'periode' => $periode];

            DB::table('reference_sequences')->insertOrIgnore($cle + ['compteur' => 0]);

            $compteur = DB::table('reference_sequences')
                ->where($cle)
                ->lockForUpdate()
                ->value('compteur');

            $prochain = (int) $compteur + 1;

            if ($prochain > 999) {
                throw new \OverflowException(
                    "La limite journalière de 999 références {$prefixe} est atteinte pour l'organisation {$organizationId} le {$periode}."
                );
            }

            DB::table('reference_sequences')->where($cle)->update(['compteur' => $prochain]);

            $reference = $prefixe.'-'.$periode.'-'.str_pad((string) $prochain, 3, '0', STR_PAD_LEFT);

            return [$reference, $prochain];
        });
    }
}
