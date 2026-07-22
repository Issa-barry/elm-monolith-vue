<?php

namespace App\Policies;

use App\Models\Employe;
use App\Models\PieceIdentite;
use App\Models\User;

class PieceIdentitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('pieces-identite.read');
    }

    public function view(User $user, PieceIdentite $pieceIdentite): bool
    {
        return $user->can('pieces-identite.read')
            && $user->organization_id === $pieceIdentite->organization_id;
    }

    public function create(User $user, Employe $employe): bool
    {
        return $user->can('pieces-identite.create')
            && $user->organization_id === $employe->organization_id;
    }

    public function update(User $user, PieceIdentite $pieceIdentite): bool
    {
        return $user->can('pieces-identite.update')
            && $user->organization_id === $pieceIdentite->organization_id;
    }

    public function delete(User $user, PieceIdentite $pieceIdentite): bool
    {
        return $user->can('pieces-identite.delete')
            && $user->organization_id === $pieceIdentite->organization_id;
    }

    public function download(User $user, PieceIdentite $pieceIdentite): bool
    {
        return $user->can('pieces-identite.download')
            && $user->organization_id === $pieceIdentite->organization_id;
    }

    public function valider(User $user, PieceIdentite $pieceIdentite): bool
    {
        return $user->can('pieces-identite.valider')
            && $user->organization_id === $pieceIdentite->organization_id;
    }

    public function rejeter(User $user, PieceIdentite $pieceIdentite): bool
    {
        return $user->can('pieces-identite.rejeter')
            && $user->organization_id === $pieceIdentite->organization_id;
    }
}
