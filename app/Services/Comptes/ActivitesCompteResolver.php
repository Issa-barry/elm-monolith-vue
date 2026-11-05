<?php

namespace App\Services\Comptes;

use App\Enums\PrestataireType;
use App\Models\EntrepriseInterlocuteur;
use App\Models\Parrain;
use App\Models\Prestataire;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Activités parrain et consultant d'un compte (ADR 0050). Lit uniquement les rattachements posés
 * (`user_id`, au back-office ou automatiquement pour la même Personne, ADR 0056), jamais le
 * téléphone : contrairement à ClientIdentityResolver, un numéro identique ne donne aucun accès. Recalculé à chaque requête : une désactivation ou un
 * détachement coupe l'accès immédiatement.
 *
 * Un parrain ou un consultant désactivé garde l'accès à son historique, sans nouvelle opération :
 * les données se lisent par bénéficiaire, et ses remplaçants ont leurs propres parts.
 */
class ActivitesCompteResolver
{
    public function resolve(User $user): ActivitesCompte
    {
        $organizationId = $user->organization_id;

        if ($organizationId === null) {
            return new ActivitesCompte(null, null, collect());
        }

        $parrain = Parrain::query()
            ->with('personne')
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->first();

        $entrepriseIds = EntrepriseInterlocuteur::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->autorises()
            ->pluck('entreprise_tierce_id');

        $consultants = Prestataire::query()
            ->with(['personne', 'entrepriseTierce'])
            ->where('organization_id', $organizationId)
            ->parType(PrestataireType::CONSULTANT)
            ->where(function (Builder $q) use ($user, $entrepriseIds) {
                $q->where(fn (Builder $personnel) => $personnel
                    ->whereNull('entreprise_tierce_id')
                    ->where('user_id', $user->id));

                if ($entrepriseIds->isNotEmpty()) {
                    $q->orWhereIn('entreprise_tierce_id', $entrepriseIds);
                }
            })
            ->orderBy('reference')
            ->get();

        return new ActivitesCompte($organizationId, $parrain, $consultants);
    }
}
