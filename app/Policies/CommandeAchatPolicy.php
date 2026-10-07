<?php

namespace App\Policies;

use App\Models\CommandeAchat;
use App\Models\User;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Services\SiteScopeService;

/**
 * Permission + périmètre de lecture (organisation, agences, créateur, validateur). Les conditions
 * d'état, le plafond et la séparation créateur/validateur sont vérifiés par CommandeAchatService /
 * ReceptionAchatService sous verrou, avec un message explicite — le Gate::before du super
 * administrateur court-circuite cette policy, jamais ces services.
 */
class CommandeAchatPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('achats.read');
    }

    public function view(User $user, CommandeAchat $commande): bool
    {
        return $user->can('achats.read') && $this->visible($user, $commande);
    }

    public function create(User $user): bool
    {
        return $user->can('achats.create');
    }

    public function update(User $user, CommandeAchat $commande): bool
    {
        return $user->can('achats.update') && $this->visible($user, $commande);
    }

    public function valider(User $user, CommandeAchat $commande): bool
    {
        return $user->can('achats.valider') && $this->visible($user, $commande);
    }

    public function annuler(User $user, CommandeAchat $commande): bool
    {
        return $user->can('achats.annuler') && $this->visible($user, $commande);
    }

    /** Réceptionner (Logistique) : permission + agence de la commande dans le périmètre de l'utilisateur. */
    public function receptionner(User $user, CommandeAchat $commande): bool
    {
        return $user->can('receptions.create')
            && $user->organization_id === $commande->organization_id
            && app(SiteScopeService::class)->siteAccessible($user, $commande->site_id);
    }

    public function delete(User $user, CommandeAchat $commande): bool
    {
        return $user->can('achats.delete') && $this->visible($user, $commande);
    }

    private function visible(User $user, CommandeAchat $commande): bool
    {
        return app(PerimetreCommandesAchat::class)->estVisible($commande, $user);
    }
}
