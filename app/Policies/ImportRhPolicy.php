<?php

namespace App\Policies;

use App\Models\ImportRh;
use App\Models\User;

/**
 * Import des dossiers et contrats RH. Le contrôle d'organisation est aussi fait explicitement dans
 * les contrôleurs : le Gate::before du super administrateur court-circuite cette policy.
 */
class ImportRhPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('imports-rh.read');
    }

    public function view(User $user, ImportRh $import): bool
    {
        return $user->can('imports-rh.read') && $user->organization_id === $import->organization_id;
    }

    /** Déposer un fichier (et télécharger le fichier pré-rempli) : dossier modifiable requis. */
    public function create(User $user): bool
    {
        return $user->can('imports-rh.create') && $user->can('rh-employes.update');
    }

    /** Confirmer ou abandonner déclenche une écriture : imports-rh.read seul ne suffit jamais. */
    public function confirm(User $user, ImportRh $import): bool
    {
        return $this->create($user) && $user->organization_id === $import->organization_id;
    }
}
