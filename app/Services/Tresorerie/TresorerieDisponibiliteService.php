<?php

namespace App\Services\Tresorerie;

use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use App\Models\CompteTresorerie;
use App\Models\EcritureComptable;
use App\Models\MouvementFonds;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Position de trésorerie réelle d'un site, calculée depuis le grand livre
 * (compta_ecritures) — jamais depuis JournalTresorerie (registre parallèle
 * hors périmètre de ce chantier, cf. compte-rendu). Le compte de virements
 * internes (58) n'est rattaché à AUCUN CompteTresorerie : il est donc
 * naturellement exclu du "disponible" d'un site, sans logique dédiée — de
 * l'argent en transit n'appartient à aucun site tant qu'il n'est pas
 * réceptionné (cf. MouvementFondsComptabilisationService).
 */
class TresorerieDisponibiliteService
{
    /**
     * Solde cumulé (débit - crédit) de tous les supports de trésorerie actifs
     * de l'AGENCE d'un site, toutes pièces comptables datées au plus tard à
     * $date — inclut donc le solde d'ouverture (toujours daté avant toute
     * activité) et tout financement déjà réceptionné à cette date.
     *
     * Les caisses dédiées à un agent sont volontairement exclues : l'argent
     * qu'elles détiennent n'est utilisable par l'agence qu'une fois versé et
     * réceptionné (décision du 2026-09-19). Il reste visible dans
     * situationParSupport() — « où est l'argent » — mais ne réduit pas le
     * besoin de financement.
     */
    public function disponiblePourSite(string $organizationId, string $siteId, Carbon $date): float
    {
        $compteComptableIds = CompteTresorerie::forOrg($organizationId)
            ->where('site_id', $siteId)
            ->actifs()
            ->agence()
            ->pluck('compte_comptable_id');

        if ($compteComptableIds->isEmpty()) {
            return 0.0;
        }

        $solde = EcritureComptable::query()
            ->whereIn('compte_comptable_id', $compteComptableIds)
            ->where('site_id', $siteId)
            ->whereHas('piece', fn ($q) => $q->where('organization_id', $organizationId)->whereDate('date_piece', '<=', $date->toDateString()))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
            ->value('solde');

        return round((float) $solde, 2);
    }

    /**
     * Fonds envoyés vers ce site mais pas encore confirmés reçus — jamais
     * comptés dans disponiblePourSite() (aucune pièce postée côté destination
     * tant que non reçu). Inclut CONTESTE : un litige non résolu ne rend les
     * fonds disponibles nulle part tant qu'il n'est pas tranché (RECU ou
     * RETOURNE).
     *
     * Si $echeanceDebut/$echeanceFin sont fournis, ne compte que les
     * mouvements visant CETTE échéance (evite qu'un même envoi couvre deux
     * besoins différents dans le calcul) plus ceux sans échéance déclarée
     * (remise/financement générique — traité prudemment comme pouvant
     * couvrir n'importe quel besoin, jamais ignoré : cf. revue Codex du
     * 2026-08-22 sur le risque de double financement).
     *
     * Ne compte que les mouvements ENTRE AGENCES : un versement d'une caisse dédiée vers la caisse
     * de l'agence (`interne_caisses`) est un transfert interne, pas un financement du siège — il
     * n'y a donc rien à « déjà financer » ni à déduire du besoin (décision du 2026-09-19).
     */
    public function fondsEnTransitVersSite(string $organizationId, string $siteId, ?Carbon $echeanceDebut = null, ?Carbon $echeanceFin = null): float
    {
        $query = MouvementFonds::where('organization_id', $organizationId)
            ->where('nature', NatureMouvementFonds::INTER_SITES->value)
            ->where('site_destination_id', $siteId)
            ->whereIn('statut', [StatutMouvementFonds::ENVOYE->value, StatutMouvementFonds::CONTESTE->value]);

        if ($echeanceDebut && $echeanceFin) {
            $query->where(fn ($q) => $q
                ->whereNull('echeance_debut')
                ->orWhere(fn ($q2) => $q2
                    ->whereDate('echeance_debut', $echeanceDebut->toDateString())
                    ->whereDate('echeance_fin', $echeanceFin->toDateString())
                )
            );
        }

        return round((float) $query->sum('montant'), 2);
    }

    /**
     * Financements reçus par ce site, confirmés dans l'intervalle donné —
     * purement informatif (déjà inclus dans disponiblePourSite()) : sert à
     * expliquer au siège combien a déjà été envoyé sur CETTE échéance.
     */
    public function dejaFinancePourSite(string $organizationId, string $siteId, Carbon $debut, Carbon $fin): float
    {
        return round((float) MouvementFonds::where('organization_id', $organizationId)
            ->where('nature', NatureMouvementFonds::INTER_SITES->value)
            ->where('site_destination_id', $siteId)
            ->where('statut', StatutMouvementFonds::RECU->value)
            ->whereBetween('date_reception', [$debut->toDateString(), $fin->toDateString()])
            ->sum('montant'), 2);
    }

    /**
     * Solde actuel de chaque support de trésorerie actif de l'organisation, au
     * plus tard $date — même source de vérité (grand livre) et même logique
     * (débit - crédit des pièces datées au plus tard $date) que
     * disponiblePourSite(), mais à la granularité du support plutôt
     * qu'agrégée par site. Sert l'écran "Situation de trésorerie".
     *
     * Attention : `compta_ecritures` ne porte pas de compte_tresorerie_id
     * (seulement compte_comptable_id + site_id, cf. EcritureComptableService)
     * — si deux supports du même site partagent le même compte comptable
     * (cas rare, non empêché à la création), ils sont indiscernables au
     * niveau du grand livre et affichent donc le même solde : c'est le reflet
     * exact de la comptabilité, pas un bug de ce calcul. Une caisse dédiée à
     * un agent n'est jamais concernée : elle a toujours son propre sous-compte
     * (cf. CaisseAgentService), donc son solde est distinct de celui de la
     * caisse de l'agence.
     *
     * Inclut TOUS les supports actifs, caisses dédiées comprises (Situation =
     * où est l'argent, contrairement à disponiblePourSite()). $inclureInactifs
     * sert l'écran Supports, qui doit aussi afficher le solde d'un support
     * désactivé.
     *
     * @return Collection<int, array{compte_tresorerie_id:string, site_id:string, agent_id:?string, libelle:string, type:string, solde:float}>
     */
    public function situationParSupport(string $organizationId, Carbon $date, bool $inclureInactifs = false): Collection
    {
        $query = CompteTresorerie::forOrg($organizationId);
        if (! $inclureInactifs) {
            $query->actifs();
        }
        $comptes = $query->get(['id', 'site_id', 'agent_id', 'compte_comptable_id', 'libelle', 'type']);

        if ($comptes->isEmpty()) {
            return collect();
        }

        $soldesParPaire = EcritureComptable::query()
            ->whereIn('compte_comptable_id', $comptes->pluck('compte_comptable_id')->unique())
            ->whereHas('piece', fn ($q) => $q->where('organization_id', $organizationId)->whereDate('date_piece', '<=', $date->toDateString()))
            ->selectRaw('site_id, compte_comptable_id, COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
            ->groupBy('site_id', 'compte_comptable_id')
            ->get()
            ->keyBy(fn ($row) => $row->site_id.'|'.$row->compte_comptable_id);

        return $comptes->map(function (CompteTresorerie $c) use ($soldesParPaire) {
            $ligne = $soldesParPaire->get($c->site_id.'|'.$c->compte_comptable_id);

            return [
                'compte_tresorerie_id' => $c->id,
                'site_id' => $c->site_id,
                'agent_id' => $c->agent_id,
                'libelle' => $c->libelle,
                'type' => $c->type->value,
                'solde' => round((float) ($ligne->solde ?? 0), 2),
            ];
        });
    }

    /**
     * Versements d'une caisse dédiée vers la caisse de l'agence (`interne_caisses`) ENVOYÉS mais pas
     * encore reçus, par caisse source — information de SUIVI pour l'interface, jamais du solde : ce
     * montant a déjà quitté la caisse de l'agent (débit du compte de transit 588000) et n'est pas
     * encore crédité à la caisse de l'agence, il n'est donc dans aucun solde de support. Lecture
     * seule des mouvements : aucun calcul comptable n'est modifié, le grand livre reste la source
     * de vérité des soldes.
     *
     * « En cours » = Envoyé ou Contesté (un litige non résolu laisse l'argent en transit, comme pour
     * fondsEnTransitVersSite()), envoyé au plus tard à $date. Pour une date passée, un versement
     * reçu APRÈS cette date y était encore en cours ; un versement retourné depuis n'est pas
     * retrouvé (le retour n'est pas daté sur le mouvement) — cas rare, sans effet à la date du jour.
     *
     * @param  list<string>|null  $supportIds  restreint aux caisses sources données (null = toutes)
     * @return Collection<int, array{compte_tresorerie_id:string, site_id:string, montant:float, nombre:int}>
     */
    public function versementsEnCours(string $organizationId, ?Carbon $date = null, ?array $supportIds = null): Collection
    {
        $jour = ($date ?? now())->toDateString();

        return MouvementFonds::where('organization_id', $organizationId)
            ->where('nature', NatureMouvementFonds::INTERNE_CAISSES->value)
            ->whereNotNull('compte_tresorerie_origine_id')
            ->whereDate('date_envoi', '<=', $jour)
            ->where(fn ($q) => $q
                ->whereIn('statut', [StatutMouvementFonds::ENVOYE->value, StatutMouvementFonds::CONTESTE->value])
                ->orWhere(fn ($q2) => $q2
                    ->where('statut', StatutMouvementFonds::RECU->value)
                    ->whereDate('date_reception', '>', $jour)
                )
            )
            ->when($supportIds !== null, fn ($q) => $q->whereIn('compte_tresorerie_origine_id', $supportIds))
            ->selectRaw('compte_tresorerie_origine_id, site_origine_id, COALESCE(SUM(montant), 0) as montant, COUNT(*) as nombre')
            ->groupBy('compte_tresorerie_origine_id', 'site_origine_id')
            ->get()
            ->map(fn ($ligne) => [
                'compte_tresorerie_id' => $ligne->compte_tresorerie_origine_id,
                'site_id' => $ligne->site_origine_id,
                'montant' => round((float) $ligne->montant, 2),
                'nombre' => (int) $ligne->nombre,
            ])
            ->values();
    }

    /**
     * Solde d'UN support (débit - crédit de son compte sur son site), source de
     * vérité = grand livre. Sans $date, toutes les pièces sont comptées, y
     * compris postdatées : sert à vérifier qu'une caisse est réellement vide
     * avant de la désactiver. Pour une caisse dédiée à un agent, le compte est
     * propre à cette caisse ; pour un support d'agence, même limite que
     * situationParSupport() si deux supports d'un site partagent leur compte.
     */
    public function soldePourSupport(CompteTresorerie $support, ?Carbon $date = null): float
    {
        $solde = EcritureComptable::query()
            ->where('compte_comptable_id', $support->compte_comptable_id)
            ->where('site_id', $support->site_id)
            ->whereHas('piece', function ($q) use ($support, $date) {
                $q->where('organization_id', $support->organization_id);
                if ($date) {
                    $q->whereDate('date_piece', '<=', $date->toDateString());
                }
            })
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
            ->value('solde');

        return round((float) $solde, 2);
    }
}
