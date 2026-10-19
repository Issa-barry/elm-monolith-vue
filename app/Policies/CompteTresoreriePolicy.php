<?php

namespace App\Policies;

use App\Models\CompteTresorerie;
use App\Models\User;

/**
 * Actions sur un support de trésorerie : sa validation (valider()) et, pour une caisse dédiée à
 * un agent, son versement vers la caisse de l'agence (verser(), chantier caisses dédiées,
 * phase 3).
 *
 * Versement : permission dédiée `tresorerie.verser`, jamais `tresorerie.envoyer` : un agent qui
 * verse SA caisse ne doit pas pour autant pouvoir envoyer de l'argent entre agences.
 *
 * Portée du versement : la caisse doit appartenir à l'organisation de l'utilisateur et à l'un de ses sites (les
 * admins ont autorité sur tous les sites). Sans `tresorerie.envoyer` — donc hors responsable — on
 * ne verse que SA propre caisse ; un responsable (avec `tresorerie.envoyer`) peut « récupérer » la
 * caisse de n'importe quel agent de son agence.
 *
 * Le Gate::before du super admin passe avant cette policy : l'état de la caisse (dédiée, active,
 * solde suffisant) est donc toujours revérifié par MouvementFondsService::verserCaisseAgent().
 */
class CompteTresoreriePolicy
{
    /**
     * Valide un support de trésorerie (brouillon → actif). Permission dédiée
     * `tresorerie.valider_supports`, distincte de la gestion des supports
     * (`tresorerie.gerer_soldes_ouverture`) : l'organisation peut confier la validation à un autre
     * profil que celui qui crée. Portée : même organisation et agence de l'utilisateur (les admins
     * ont autorité sur tous les sites). L'état (brouillon) et les règles propres à une caisse
     * dédiée sont garantis par SupportTresorerieValidationService — le Gate::before du super admin
     * passe avant cette policy.
     */
    public function valider(User $user, CompteTresorerie $support): bool
    {
        if (! $user->can('tresorerie.valider_supports') || $user->organization_id !== $support->organization_id) {
            return false;
        }

        return $user->agitSurToutesLesAgences() || $user->isAssignedToSite($support->site_id);
    }

    /**
     * Supprime un support resté en brouillon (ADR 0028). Permission dédiée
     * `tresorerie.supprimer_supports`, même portée que la validation. L'état (jamais validé, aucune
     * opération) est garanti par SupportTresorerieSuppressionService — le Gate::before du super
     * admin passe avant cette policy.
     */
    public function supprimer(User $user, CompteTresorerie $support): bool
    {
        if (! $user->can('tresorerie.supprimer_supports') || $user->organization_id !== $support->organization_id) {
            return false;
        }

        return $user->agitSurToutesLesAgences() || $user->isAssignedToSite($support->site_id);
    }

    public function verser(User $user, CompteTresorerie $caisse): bool
    {
        if (! $user->can('tresorerie.verser') || $user->organization_id !== $caisse->organization_id) {
            return false;
        }

        // Caisse en espèces d'un agent seulement : son compte Mobile Money ne se « verse » pas.
        if (! $caisse->isCaisseDediee()) {
            return false;
        }

        if (! $user->agitSurToutesLesAgences() && ! $user->isAssignedToSite($caisse->site_id)) {
            return false;
        }

        return $caisse->agent_id === $user->id || $user->can('tresorerie.envoyer');
    }

    /**
     * Transférer le compte Mobile Money dédié d'un agent vers le Mobile Money de l'agence (ADR
     * 0027) : mêmes droits que le versement de caisse — `tresorerie.verser`, agence de
     * l'utilisateur (admins et superviseur : toutes), son propre compte seulement, sauf `tresorerie.envoyer` qui
     * permet d'enregistrer le transfert d'un autre agent de l'agence. État et solde sont revérifiés
     * par MouvementFondsService::transfererMobileMoneyAgent() (Gate::before du super admin).
     */
    public function transfererMobileMoney(User $user, CompteTresorerie $compte): bool
    {
        if (! $user->can('tresorerie.verser') || $user->organization_id !== $compte->organization_id) {
            return false;
        }

        if (! $compte->isMobileMoneyDedie()) {
            return false;
        }

        if (! $user->agitSurToutesLesAgences() && ! $user->isAssignedToSite($compte->site_id)) {
            return false;
        }

        return $compte->agent_id === $user->id || $user->can('tresorerie.envoyer');
    }

    /**
     * Approvisionner la caisse dédiée d'un agent depuis cette caisse de l'agence (ADR 0018) :
     * `tresorerie.envoyer` (sortie de fonds de la caisse de l'agence), même organisation, agence de
     * l'utilisateur (admins et superviseur : toutes). Ce droit ne donne jamais celui de confirmer la réception,
     * réservée à l'agent bénéficiaire. Type, état et solde de la caisse sont revérifiés par
     * MouvementFondsService::approvisionnerCaisseAgent() (Gate::before du super admin).
     */
    public function approvisionner(User $user, CompteTresorerie $caisse): bool
    {
        if (! $user->can('tresorerie.envoyer') || $user->organization_id !== $caisse->organization_id) {
            return false;
        }

        if ($caisse->isDediee()) {
            return false;
        }

        return $user->agitSurToutesLesAgences() || $user->isAssignedToSite($caisse->site_id);
    }

    /**
     * Déposer en banque depuis ce support de l'agence (caisse ou Mobile Money, ADR 0026) : même
     * droit que l'approvisionnement — c'est une sortie de fonds d'un support de l'agence
     * (`tresorerie.envoyer`, agence de l'utilisateur). Type, état et solde sont revérifiés par
     * MouvementFondsService::deposerEnBanque().
     */
    public function deposerEnBanque(User $user, CompteTresorerie $support): bool
    {
        return $this->approvisionner($user, $support);
    }
}
