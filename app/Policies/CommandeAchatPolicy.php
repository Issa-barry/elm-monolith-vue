<?php

namespace App\Policies;

use App\Models\CommandeAchat;
use App\Models\User;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Services\Achats\PerimetreReceptionsAchat;

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
        return $user->can('achats.update') && $this->peutAgir($user, $commande);
    }

    public function valider(User $user, CommandeAchat $commande): bool
    {
        return $user->can('achats.valider') && $this->peutAgir($user, $commande);
    }

    public function annuler(User $user, CommandeAchat $commande): bool
    {
        return $user->can('achats.annuler') && $this->peutAgir($user, $commande);
    }

    /**
     * Réceptionner (Logistique) : permission + agence de la commande couverte — l'utilisateur y est
     * rattaché, ou une règle « Peut réceptionner pour » de son rôle la couvre.
     */
    public function receptionner(User $user, CommandeAchat $commande): bool
    {
        return $user->can('receptions.create') && self::couvreAgenceDeReception($user, $commande);
    }

    /** Sans passe-droit de rôle : vérifié aussi par le contrôleur (Gate::before du super administrateur). */
    public static function couvreAgenceDeReception(User $user, CommandeAchat $commande): bool
    {
        return app(PerimetreReceptionsAchat::class)->couvre($user, $commande);
    }

    public function delete(User $user, CommandeAchat $commande): bool
    {
        return $user->can('achats.delete') && $this->peutAgir($user, $commande);
    }

    /** Les deux agences du bon (livraison et paiement) sont dans le périmètre de l'utilisateur. */
    private function peutAgir(User $user, CommandeAchat $commande): bool
    {
        return app(PerimetreCommandesAchat::class)->peutAgir($commande, $user);
    }
}
