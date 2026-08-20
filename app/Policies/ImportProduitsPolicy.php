<?php

namespace App\Policies;

use App\Models\ImportProduits;
use App\Models\User;

class ImportProduitsPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('imports-produits.read');
    }

    public function view(User $user, ImportProduits $importProduits): bool
    {
        return $user->can('imports-produits.read')
            && $user->organization_id === $importProduits->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('imports-produits.create');
    }

    /**
     * Confirmer/relancer déclenche réellement l'écriture (création/mise à jour de produits) —
     * `view` (imports-produits.read seul) ne doit jamais suffire à lancer cette action, même si
     * l'utilisateur possède par ailleurs produits.create/produits.update : ces deux permissions
     * ne gouvernent QUE le contenu autorisé du fichier (cf. ImportProduitsExecutor), pas le
     * droit de déclencher l'exécution d'un import elle-même, qui reste imports-produits.create —
     * la même permission que pour en déposer un nouveau.
     */
    public function confirm(User $user, ImportProduits $importProduits): bool
    {
        return $user->can('imports-produits.create')
            && $user->organization_id === $importProduits->organization_id;
    }

    public function retry(User $user, ImportProduits $importProduits): bool
    {
        return $this->confirm($user, $importProduits);
    }
}
