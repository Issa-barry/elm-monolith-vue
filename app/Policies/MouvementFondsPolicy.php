<?php

namespace App\Policies;

use App\Models\MouvementFonds;
use App\Models\User;

/**
 * Séparation création/réception (règle #11 de la spec) : le site d'origine
 * initie/envoie, le site de destination confirme la réception ou rejette —
 * jamais la même personne des deux côtés d'affilée sauf si admin (autorité
 * globale sur l'organisation, comme TransfertLogistiquePolicy).
 */
class MouvementFondsPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tresorerie.read');
    }

    public function view(User $user, MouvementFonds $mouvement): bool
    {
        return $user->can('tresorerie.read') && $this->sameOrganization($user, $mouvement);
    }

    public function create(User $user): bool
    {
        return $user->can('tresorerie.create');
    }

    public function envoyer(User $user, MouvementFonds $mouvement): bool
    {
        if (! $user->can('tresorerie.envoyer') || ! $this->sameOrganization($user, $mouvement) || ! $mouvement->isBrouillon()) {
            return false;
        }

        return $user->isAdmin() || $user->isAssignedToSite($mouvement->site_origine_id);
    }

    public function recevoir(User $user, MouvementFonds $mouvement): bool
    {
        if (! $user->can('tresorerie.recevoir') || ! $this->sameOrganization($user, $mouvement) || ! $mouvement->isEnvoye()) {
            return false;
        }

        return $user->isAdmin() || $user->isAssignedToSite($mouvement->site_destination_id);
    }

    public function annuler(User $user, MouvementFonds $mouvement): bool
    {
        if (! $user->can('tresorerie.annuler') || ! $this->sameOrganization($user, $mouvement) || ! $mouvement->isBrouillon()) {
            return false;
        }

        return $user->isAdmin() || $user->isAssignedToSite($mouvement->site_origine_id);
    }

    public function rejeter(User $user, MouvementFonds $mouvement): bool
    {
        if (! $user->can('tresorerie.rejeter') || ! $this->sameOrganization($user, $mouvement) || ! $mouvement->isEnvoye()) {
            return false;
        }

        return $user->isAdmin() || $user->isAssignedToSite($mouvement->site_destination_id);
    }

    private function sameOrganization(User $user, MouvementFonds $mouvement): bool
    {
        return $user->organization_id === $mouvement->organization_id;
    }
}
