<?php

namespace App\Services\Tresorerie;

use App\Enums\ModePaiement;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use Illuminate\Support\Carbon;

/**
 * Détermine la caisse dédiée qui doit recevoir un encaissement de vente (phase 2 du
 * chantier caisses dédiées, ADR 0001). Un encaissement alimente la caisse de l'agent
 * qui l'a enregistré quand TOUTES ces conditions sont réunies — sinon il suit le
 * comportement historique (compte de trésorerie résolu par compta_mappings, 571000
 * pour les espèces) :
 *
 *  - le paiement est en **espèces** : Mobile Money, virement et chèque ne sont pas de
 *    l'argent physiquement détenu par l'agent et gardent leurs supports habituels ;
 *  - l'auteur de l'encaissement (`created_by`) a une caisse dédiée **active** sur le site
 *    de la facture — la pièce comptable est déjà rattachée à ce site, la caisse doit
 *    l'être aussi (une seule caisse active par agent et par site, cf. CaisseAgentService,
 *    donc jamais d'ambiguïté) ;
 *  - la caisse était en service au moment de l'encaissement : jamais de reclassement
 *    rétroactif de l'historique. La mise en service est la VALIDATION de la caisse
 *    (`valide_le`), pas sa création en brouillon : tant qu'elle n'est pas validée, elle est
 *    inutilisable et les encaissements de l'agent suivent le comportement historique. Deux
 *    gardes complémentaires — la date de l'encaissement n'est pas antérieure à la mise en
 *    service, et l'encaissement lui-même n'a pas été enregistré avant (cas d'un rattrapage
 *    comptable, cf. ComptabiliteRattrapageCommand, qui repasse sur d'anciens encaissements).
 */
class CaisseAgentResolver
{
    public function pourEncaissement(EncaissementVente $encaissement, FactureVente $facture): ?CompteTresorerie
    {
        if ($encaissement->mode_paiement !== ModePaiement::ESPECES) {
            return null;
        }

        if (! $encaissement->created_by || ! $facture->site_id || ! $facture->organization_id) {
            return null;
        }

        $caisse = CompteTresorerie::forOrg($facture->organization_id)
            ->dediees()
            ->actifs()
            ->where('agent_id', $encaissement->created_by)
            ->where('site_id', $facture->site_id)
            ->first();

        if (! $caisse) {
            return null;
        }

        // Une caisse active est toujours validée (invariant du modèle) ; `created_at` n'est là
        // que par prudence.
        $miseEnService = $caisse->valide_le ?? $caisse->created_at;

        if ($encaissement->date_encaissement
            && Carbon::parse($encaissement->date_encaissement)->startOfDay()->lt($miseEnService->copy()->startOfDay())) {
            return null;
        }

        if ($encaissement->created_at && $encaissement->created_at->lt($miseEnService)) {
            return null;
        }

        return $caisse;
    }
}
