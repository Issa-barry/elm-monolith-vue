<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\User\UserFormOptions;
use Inertia\Inertia;
use Inertia\Response;

class EditUserController extends Controller
{
    public function __invoke(User $user): Response
    {
        $this->authorize('update', $user);

        // Org du compte CIBLE, pas de l'acteur : un super_admin édite depuis la console
        // plateforme (/backoffice/comptes) des agents de n'importe quelle organisation,
        // pas seulement la sienne — utiliser son propre organization_id renverrait la
        // liste de sites de la MAUVAISE organisation.
        $orgId = $user->organization_id;
        $defaultSite = $user->sites()->wherePivot('is_default', true)->first();

        return Inertia::render('Users/Edit', [
            'user' => [
                'id' => $user->id,
                'prenom' => $user->prenom,
                'nom' => $user->nom,
                'email' => $user->email,
                'telephone' => $user->telephone,
                'code_pays' => $user->code_pays,
                'ville' => $user->ville,
                'adresse' => $user->adresse,
                'role' => $user->getRoleNames()->first(),
                'site_id' => $defaultSite?->id,
                'is_active' => $user->is_active,
                'matricule' => $user->matricule,
            ],
            'roles' => UserFormOptions::getRoleOptions($orgId, auth()->user()->isSuperAdmin()),
            'sites' => $orgId ? UserFormOptions::getSiteOptions($orgId) : [],
            'is_me' => $user->id === auth()->id(),
        ]);
    }
}
