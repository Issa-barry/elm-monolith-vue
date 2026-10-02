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

        return $user->isAdmin() || $user->isAssignedToSite($support->site_id);
    }

    public function verser(User $user, CompteTresorerie $caisse): bool
    {
        if (! $user->can('tresorerie.verser') || $user->organization_id !== $caisse->organization_id) {
            return false;
        }

        if (! $caisse->isDediee()) {
            return false;
        }

        if (! $user->isAdmin() && ! $user->isAssignedToSite($caisse->site_id)) {
            return false;
        }

        return $caisse->agent_id === $user->id || $user->can('tresorerie.envoyer');
    }
}
