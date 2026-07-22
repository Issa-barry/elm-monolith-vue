<?php

namespace App\Policies;

use App\Models\PieceIdentite;
use App\Models\Proprietaire;
use App\Models\User;

class PieceIdentitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('pieces-identite.read');
    }

    public function view(User $user, PieceIdentite $pieceIdentite): bool
    {
        return $user->can('pieces-identite.read') && $this->estAccessible($user, $pieceIdentite);
    }

    public function create(User $user, Proprietaire $proprietaire): bool
    {
        return $user->can('pieces-identite.create')
            && $user->organization_id === $proprietaire->organization_id;
    }

    public function update(User $user, PieceIdentite $pieceIdentite): bool
    {
        return $user->can('pieces-identite.update') && $this->estAccessible($user, $pieceIdentite);
    }

    public function delete(User $user, PieceIdentite $pieceIdentite): bool
    {
        return $user->can('pieces-identite.delete') && $this->estAccessible($user, $pieceIdentite);
    }

    public function download(User $user, PieceIdentite $pieceIdentite): bool
    {
        return $user->can('pieces-identite.download') && $this->estAccessible($user, $pieceIdentite);
    }

    public function valider(User $user, PieceIdentite $pieceIdentite): bool
    {
        return $user->can('pieces-identite.valider') && $this->estAccessible($user, $pieceIdentite);
    }

    public function rejeter(User $user, PieceIdentite $pieceIdentite): bool
    {
        return $user->can('pieces-identite.rejeter') && $this->estAccessible($user, $pieceIdentite);
    }

    /**
     * Défense en profondeur : au-delà de organization_id sur la pièce elle-même
     * (déjà fiable grâce au garde-fou modèle), revérifie explicitement que
     * l'entité rattachée est bien un Proprietaire de la même organisation que
     * l'utilisateur — jamais d'autorisation sur la seule base de l'ULID connu.
     */
    private function estAccessible(User $user, PieceIdentite $pieceIdentite): bool
    {
        if ($user->organization_id !== $pieceIdentite->organization_id) {
            return false;
        }

        $identifiable = $pieceIdentite->identifiable;

        return $identifiable instanceof Proprietaire
            && $identifiable->organization_id === $user->organization_id;
    }
}
