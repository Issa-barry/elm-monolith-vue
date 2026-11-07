<?php

namespace App\Policies;

use App\Models\EntrepriseTierce;
use App\Models\User;
use App\Services\Contacts\VisibiliteContacts;
use Illuminate\Auth\Access\Response;

/**
 * Fiche Entreprise du référentiel Contacts (ADR 0060, lot 2) — lecture seule. Une entreprise d'une
 * autre organisation, ou dont aucune fonction n'est visible du lecteur, répond 404 (CONTACT-014).
 */
class EntrepriseTiercePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contacts.read');
    }

    public function view(User $user, EntrepriseTierce $entreprise): Response
    {
        if (! $user->can('contacts.read')) {
            return Response::deny();
        }

        return app(VisibiliteContacts::class)->entrepriseVisible($entreprise, $user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
