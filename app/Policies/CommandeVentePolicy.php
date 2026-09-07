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

    /**
     * Modifier le CONTENU de la commande (lignes, quantités...) — jamais après le démarrage du
     * chargement (règle actuelle : "modifiable uniquement en BROUILLON", cf.
     * StatutCommandeVente::isEditable()). Ability distincte de update() ci-dessus, qui reste
     * permission+organisation seule : `can_encaisser` et relancerCommissions() (cf.
     * CommandeVenteController) s'exercent volontairement sur des commandes déjà sorties de
     * BROUILLON et continuent donc de s'appuyer sur `update()` telle quelle. Avant cette
     * méthode, `isEditable()` n'était vérifié qu'à la main dans le contrôleur (abort_if séparé
     * + recalcul du flag `can_modifier` dupliqué à 2 endroits) — jamais dans la Policy
     * elle-même, donc absent de tout futur appelant qui autoriserait via `update()` seule.
     */
    public function modifierContenu(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.update')
            && $this->sameOrganization($user, $commande)
            && $commande->isEditable();
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

    /**
     * Valider la réception (LIVRAISON_EN_COURS → LIVREE) — réservée aux commandes nécessitant une
     * réception explicite (cf. CommandeVente::requiertReceptionExplicite() : distribution_client,
     * et depuis le 06/09/2026 Grossiste + Livraison, cf. docs/grossiste.md). Même niveau de
     * permission que les autres transitions du workflow (ventes.update), pas un accès élevé
     * distinct : contrairement au transfert logistique, il n'y a pas ici de partie tierce (usine)
     * à faire arbitrer par un admin — c'est ELM elle-même qui constate la réception chez son
     * propre client.
     */
    public function validerReception(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.update')
            && $this->sameOrganization($user, $commande)
            && $commande->isLivraisonEnCours()
            && $commande->requiertReceptionExplicite();
    }

    /** Avancer d'une étape — agrège les quatre transitions ci-dessus */
    public function avancerStatut(User $user, CommandeVente $commande): bool
    {
        if (! $this->sameOrganization($user, $commande)) {
            return false;
        }

        return match ($commande->statut) {
            default => $this->confirmer($user, $commande)
                       || $this->demarrerChargement($user, $commande)
                       || $this->validerChargement($user, $commande)
                       || $this->validerReception($user, $commande),
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
