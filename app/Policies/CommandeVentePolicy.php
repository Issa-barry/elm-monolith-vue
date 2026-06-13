<?php

namespace App\Policies;

use App\Models\CommandeVente;
use App\Models\User;

class CommandeVentePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ventes.read');
    }

    public function view(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.read')
            && $this->sameOrganization($user, $commande);
    }

    public function create(User $user): bool
    {
        return $user->can('ventes.create');
    }

    public function update(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.update')
            && $this->sameOrganization($user, $commande);
    }

    public function delete(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.delete')
            && $this->sameOrganization($user, $commande);
    }

    /** Confirmer (BROUILLON → A_CHARGER) */
    public function confirmer(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.update')
            && $this->sameOrganization($user, $commande)
            && $commande->isBrouillon();
    }

    /** Démarrer le chargement (A_CHARGER → CHARGEMENT_EN_COURS) */
    public function demarrerChargement(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.update')
            && $this->sameOrganization($user, $commande)
            && $commande->isACharger();
    }

    /** Valider le chargement (CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS) */
    public function validerChargement(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.update')
            && $this->sameOrganization($user, $commande)
            && $commande->isChargementEnCours();
    }

    /** Avancer d'une étape — agrège les trois transitions ci-dessus */
    public function avancerStatut(User $user, CommandeVente $commande): bool
    {
        if (! $this->sameOrganization($user, $commande)) {
            return false;
        }

        return match ($commande->statut) {
            default => $this->confirmer($user, $commande)
                       || $this->demarrerChargement($user, $commande)
                       || $this->validerChargement($user, $commande),
        };
    }

    /**
     * Annulation — admins uniquement, depuis BROUILLON ou A_CHARGER.
     */
    public function annuler(User $user, CommandeVente $commande): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_entreprise'])
            && $this->sameOrganization($user, $commande)
            && $commande->statut->isAnnulable();
    }

    private function sameOrganization(User $user, CommandeVente $commande): bool
    {
        return $user->organization_id === $commande->organization_id;
    }
}
