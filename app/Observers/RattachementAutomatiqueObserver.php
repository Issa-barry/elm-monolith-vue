<?php

namespace App\Observers;

use App\Models\Personne;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Services\Comptes\RattachementAutomatiqueService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Relie automatiquement les fiches d'une personne à son compte (ADR 0056), dans les deux sens : une
 * fiche créée (ou dont la personne ou le numéro change) pour quelqu'un qui a déjà un compte ; un compte
 * créé, activé, ou qui reçoit une fiche Personne ou un numéro ; une activité nouvellement reliée dont la
 * personne porte d'autres fiches. Exécuté après la validation de la transaction : un échec ici n'annule
 * jamais l'opération d'origine, la fiche reste au rattachement manuel et la commande
 * comptes:regulariser-rattachements la retrouve.
 */
class RattachementAutomatiqueObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly RattachementAutomatiqueService $service) {}

    public function saved(Model $model): void
    {
        try {
            match (true) {
                $model instanceof User => $this->compteEnregistre($model),
                $model instanceof UserAuthIdentity => $this->identiteEnregistree($model),
                $model instanceof Personne => $this->personneEnregistree($model),
                default => $this->ficheEnregistree($model),
            };
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function compteEnregistre(User $compte): void
    {
        // Le statut compte : une fiche refusée à l'inscription (e-mail à valider) suit l'activation.
        if ($compte->wasRecentlyCreated || $compte->wasChanged(['personne_id', 'status', 'is_active'])) {
            $this->service->rattacherCompte($compte->fresh(), 'compte');
        }
    }

    private function identiteEnregistree(UserAuthIdentity $identite): void
    {
        $numeroNouveau = $identite->wasRecentlyCreated || $identite->wasChanged('normalized_value');
        $compte = $identite->type === UserAuthIdentity::TYPE_TELEPHONE && $numeroNouveau ? User::find($identite->user_id) : null;

        if ($compte !== null) {
            $this->service->rattacherCompte($compte, 'compte');
        }
    }

    private function personneEnregistree(Personne $personne): void
    {
        if ($personne->wasChanged('telephone_normalise')) {
            $this->service->rattacherPersonne((string) $personne->id, 'fiche');
        }
    }

    private function ficheEnregistree(Model $fiche): void
    {
        $source = match (true) {
            // Avant « fiche » : une instance créée puis reliée garde wasRecentlyCreated.
            $fiche->user_id !== null && $fiche->wasChanged('user_id') => 'activite_reliee',
            $fiche->wasRecentlyCreated || $fiche->wasChanged('personne_id') => 'fiche',
            default => null,
        };

        if ($source !== null && $fiche->personne_id !== null) {
            $this->service->rattacherPersonne((string) $fiche->personne_id, $source);
        }
    }
}
