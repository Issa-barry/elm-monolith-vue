<?php

namespace App\Services\Logistique;

use App\Models\Livreur;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Services\Client\ClientIdentityResolver;

/**
 * Fiche livreur d'un compte sur les routes logistiques du livreur (ADR 0059). Le lien posé sur la
 * fiche prime ; le repli par téléphone de ClientIdentityResolver ne retient qu'une fiche non réclamée.
 * Un compte sans organisation n'y a pas droit : ce repli chercherait alors dans toutes les
 * organisations, sur un numéro dont la possession n'est pas prouvée.
 */
class LivreurCompteResolver
{
    public function __construct(private readonly ClientIdentityResolver $identites) {}

    public function pourCompte(User $user): ?Livreur
    {
        $relie = Livreur::query()
            ->where('user_id', $user->id)
            ->when($user->organization_id !== null, fn ($q) => $q->where('organization_id', $user->organization_id))
            ->first();

        if ($relie !== null || $user->organization_id === null) {
            return $relie;
        }

        return $this->identites->resolve($user)->livreur;
    }

    /** Le compte est-il livreur de l'équipe chargée de ce transfert, dans la même organisation ? */
    public function concerne(User $user, TransfertLogistique $transfert): bool
    {
        $livreur = $this->pourCompte($user);

        return $livreur !== null
            && $transfert->equipe_livraison_id !== null
            && $livreur->organization_id === $transfert->organization_id
            && $livreur->equipes()->where('equipes_livraison.id', $transfert->equipe_livraison_id)->exists();
    }
}
