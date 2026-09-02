<?php

namespace App\Policies;

use App\Models\ImportVehiculesMaj;
use App\Models\User;

class ImportVehiculesMajPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('imports-vehicules-maj.read');
    }

    public function view(User $user, ImportVehiculesMaj $importVehiculesMaj): bool
    {
        return $user->can('imports-vehicules-maj.read')
            && $user->organization_id === $importVehiculesMaj->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('imports-vehicules-maj.create');
    }

    /**
     * Confirmer/relancer déclenche réellement l'écriture (mise à jour de véhicules) — `view`
     * (imports-vehicules-maj.read seul) ne doit jamais suffire à lancer cette action, même si
     * l'utilisateur possède par ailleurs vehicules.update : cette permission ne gouverne QUE le
     * contenu autorisé du fichier (cf. ImportVehiculesMajExecutor), pas le droit de déclencher
     * l'exécution d'un import elle-même, qui reste imports-vehicules-maj.create — la même
     * permission que pour en déposer un nouveau (même principe qu'ImportProduitsPolicy).
     */
    public function confirm(User $user, ImportVehiculesMaj $importVehiculesMaj): bool
    {
        return $user->can('imports-vehicules-maj.create')
            && $user->organization_id === $importVehiculesMaj->organization_id;
    }

    public function retry(User $user, ImportVehiculesMaj $importVehiculesMaj): bool
    {
        return $this->confirm($user, $importVehiculesMaj);
    }
}
