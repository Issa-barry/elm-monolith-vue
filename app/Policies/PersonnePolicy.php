<?php

namespace App\Policies;

use App\Models\Personne;
use App\Models\User;
use App\Services\Contacts\VisibiliteContacts;
use Illuminate\Auth\Access\Response;

/**
 * Fiche Personne du référentiel Contacts (ADR 0060, lot 2) — lecture seule. Une personne d'une
 * autre organisation, ou dont aucune fonction n'est visible du lecteur, répond 404 : la fiche ne
 * doit pas révéler son existence (CONTACT-014).
 */
class PersonnePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contacts.read');
    }

    /** Créer une fiche, ou lui ajouter une fonction (lot 3) ; la permission de la fonction est vérifiée en plus. */
    public function create(User $user): bool
    {
        return $user->can('contacts.create');
    }

    /** Identité de la fiche (lot 7a) ; la visibilité est vérifiée en plus par le contrôleur. */
    public function modifierIdentite(User $user): bool
    {
        return $user->can('contacts.update');
    }

    /** Catégories libres de la fiche (lot 6a) ; la visibilité de la fiche est vérifiée en plus par le contrôleur. */
    public function modifierCategories(User $user): bool
    {
        return $user->can('contacts.update');
    }

    /** Lot 9b (F4) : fusion et annulation ; visibilité, organisation et blocages vérifiés par FusionContacts. */
    public function fusionner(User $user): bool
    {
        return $user->can('contacts.read') && $user->can('contacts.fusionner');
    }

    public function view(User $user, Personne $personne): Response
    {
        if (! $user->can('contacts.read')) {
            return Response::deny();
        }

        return app(VisibiliteContacts::class)->personneVisible($personne, $user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
