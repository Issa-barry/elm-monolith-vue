<?php

namespace App\Policies;

use App\Models\CommandeAchat;
use App\Models\User;
use App\Services\Achats\PerimetreCommandesAchat;

/**
 * Permission + périmètre « Peut acheter pour » (PerimetreCommandesAchat : règles des rôles, plus le
 * créateur et le validateur). Les conditions
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
        return $user->can('achats.read') && app(PerimetreCommandesAchat::class)->estConsultable($commande, $user);
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

    /** Réceptionner (Logistique) : permission + utilisateur rattaché à l'agence de la commande. */
    public function receptionner(User $user, CommandeAchat $commande): bool
    {
        return $user->can('receptions.create') && self::estRattacheAgence($user, $commande);
    }

    /** Sans passe-droit de rôle : vérifié aussi par le contrôleur (Gate::before du super administrateur). */
    public static function estRattacheAgence(User $user, CommandeAchat $commande): bool
    {
        return $commande->site_id !== null
            && $user->organization_id === $commande->organization_id
            && $user->sites()->where('sites.id', $commande->site_id)->exists();
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
