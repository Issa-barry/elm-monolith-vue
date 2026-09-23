<?php

namespace App\Services\Tresorerie;

use App\Enums\ModePaiement;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

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
 *
 * Ce « comportement historique » ne concerne plus que les encaissements DÉJÀ enregistrés
 * (et le rattrapage comptable) : depuis le 23/09/2026, un NOUVEL encaissement en espèces est
 * refusé à l'enregistrement (garantirCaissePourEspeces(), appelée par
 * Ventes\StoreEncaissementVenteController) tant que son auteur n'a pas de caisse dédiée active
 * sur le site de la facture — plus aucun espèce ne peut arriver sur un compte sans responsable.
 */
class CaisseAgentResolver
{
    public const MESSAGE_SANS_CAISSE = "Vous ne disposez pas d'une caisse active sur ce site : impossible d'encaisser en espèces. Contactez votre responsable pour qu'il vous en crée une.";

    public const MESSAGE_AVANT_MISE_EN_SERVICE = "La date d'encaissement est antérieure à la mise en service de votre caisse : impossible de l'encaisser en espèces à cette date.";

    /** Caisse dédiée ACTIVE (donc validée, invariant du modèle) de cet agent sur ce site, s'il en a une. */
    public function caisseActive(string $organizationId, string $agentId, string $siteId): ?CompteTresorerie
    {
        return CompteTresorerie::forOrg($organizationId)
            ->dediees()
            ->actifs()
            ->where('agent_id', $agentId)
            ->where('site_id', $siteId)
            ->first();
    }

    /**
     * Sites où cet agent a une caisse dédiée active — une seule requête pour une liste entière
     * (indicateur `peut_encaisser_especes` des écrans Ventes/Factures), au lieu d'une par ligne.
     *
     * @return list<string>
     */
    public function sitesAvecCaisseActive(string $organizationId, string $agentId): array
    {
        return CompteTresorerie::forOrg($organizationId)
            ->dediees()
            ->actifs()
            ->where('agent_id', $agentId)
            ->pluck('site_id')
            ->all();
    }

    public function pourEncaissement(EncaissementVente $encaissement, FactureVente $facture): ?CompteTresorerie
    {
        if ($encaissement->mode_paiement !== ModePaiement::ESPECES) {
            return null;
        }

        if (! $encaissement->created_by || ! $facture->site_id || ! $facture->organization_id) {
            return null;
        }

        return $this->caisseEnServicePour(
            $facture->organization_id,
            $encaissement->created_by,
            $facture->site_id,
            $encaissement->date_encaissement ? Carbon::parse($encaissement->date_encaissement) : null,
            $encaissement->created_at,
        );
    }

    /**
     * Règle d'entrée d'un NOUVEL encaissement en espèces : il doit atterrir dans la caisse dédiée
     * de son auteur, jamais sur le compte partagé de l'agence (571000) où l'on ne saurait plus qui
     * détient l'argent. Garantie côté serveur, indépendante du bouton désactivé côté interface —
     * elle réutilise exactement les conditions de pourEncaissement(), de sorte que « accepté ici »
     * et « comptabilisé dans une caisse dédiée » ne peuvent jamais diverger. Sans effet pour tout
     * autre mode de paiement.
     *
     * @throws ValidationException
     */
    public function garantirCaissePourEspeces(string $modePaiement, string $agentId, FactureVente $facture, ?string $dateEncaissement): void
    {
        if ($modePaiement !== ModePaiement::ESPECES->value) {
            return;
        }

        if (! $facture->site_id || ! $this->caisseActive($facture->organization_id, $agentId, $facture->site_id)) {
            throw ValidationException::withMessages(['mode_paiement' => self::MESSAGE_SANS_CAISSE]);
        }

        // Caisse active mais mise en service APRÈS la date d'encaissement demandée : le routage
        // l'écarterait (jamais de reclassement rétroactif) et l'argent retomberait sur 571000.
        $caisse = $this->caisseEnServicePour(
            $facture->organization_id,
            $agentId,
            $facture->site_id,
            $dateEncaissement ? Carbon::parse($dateEncaissement) : null,
            null,
        );

        if (! $caisse) {
            throw ValidationException::withMessages(['date_encaissement' => self::MESSAGE_AVANT_MISE_EN_SERVICE]);
        }
    }

    private function caisseEnServicePour(string $organizationId, string $agentId, string $siteId, ?Carbon $dateEncaissement, ?Carbon $enregistreLe): ?CompteTresorerie
    {
        $caisse = $this->caisseActive($organizationId, $agentId, $siteId);

        if (! $caisse) {
            return null;
        }

        // Une caisse active est toujours validée (invariant du modèle) ; `created_at` n'est là
        // que par prudence.
        $miseEnService = $caisse->valide_le ?? $caisse->created_at;

        if ($dateEncaissement && $dateEncaissement->copy()->startOfDay()->lt($miseEnService->copy()->startOfDay())) {
            return null;
        }

        if ($enregistreLe && $enregistreLe->lt($miseEnService)) {
            return null;
        }

        return $caisse;
    }
}
