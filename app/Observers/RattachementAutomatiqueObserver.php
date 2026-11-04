<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Comptes\RattachementAutomatiqueService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Relie automatiquement les fiches d'une personne à son compte dès que l'identité est établie
 * (ADR 0056), dans les deux sens : une fiche créée (ou dont la personne change) pour quelqu'un qui a
 * déjà un compte, un compte qui reçoit la fiche Personne d'activités existantes, ou une activité
 * nouvellement reliée dont la personne porte d'autres fiches. Exécuté après la validation de la
 * transaction : un échec ici n'annule jamais l'opération d'origine, la fiche reste au rattachement
 * manuel et la commande comptes:regulariser-rattachements la retrouve.
 */
class RattachementAutomatiqueObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly RattachementAutomatiqueService $service) {}

    public function saved(Model $model): void
    {
        $personneId = $model->personne_id;
        if ($personneId === null) {
            return;
        }

        $source = match (true) {
            $model instanceof User => $model->wasRecentlyCreated || $model->wasChanged('personne_id') ? 'compte' : null,
            // Avant « fiche » : une instance créée puis reliée garde wasRecentlyCreated.
            $model->user_id !== null && $model->wasChanged('user_id') => 'activite_reliee',
            $model->wasRecentlyCreated || $model->wasChanged('personne_id') => 'fiche',
            default => null,
        };

        if ($source === null) {
            return;
        }

        try {
            $this->service->rattacherPersonne((string) $personneId, $source);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
