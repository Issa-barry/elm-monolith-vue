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
     * permission+organisation seule : relancerCommissions() (cf.
     * Ventes\RelancerCommissionsCommandeVenteController) s'exerce volontairement sur des commandes
     * déjà sorties de BROUILLON et continue donc de s'appuyer sur `update()` telle quelle
     * (`can_encaisser`, lui, vérifie sa propre permission `factures.encaisser` depuis le
     * 13/09/2026 — cf. Ventes\ShowCommandeVenteController). Avant cette
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

    /**
     * Démarrer le chargement (A_CHARGER → CHARGEMENT_EN_COURS).
     * Permission dédiée `ventes.demarrer_chargement`, indépendante de `ventes.update` depuis le
     * 13/09/2026 : décision produit — un livreur/agent "chargement" peut démarrer un chargement
     * sans pouvoir modifier le contenu de la commande ni valider les étapes suivantes. Avant
     * cette date, `ventes.update` seul suffisait pour les quatre transitions du workflow (cf.
     * migration backfill_ventes_workflow_permissions qui a préservé la capacité de tous les
     * rôles existants ayant déjà `ventes.update`).
     */
    public function demarrerChargement(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.demarrer_chargement')
            && $this->sameOrganization($user, $commande)
            && $commande->isACharger();
    }

    /**
     * Valider le chargement (CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS).
     * Permission dédiée `ventes.valider_chargement`, indépendante de `ventes.update` — voir la
     * docblock de demarrerChargement() ci-dessus pour le contexte de cette séparation.
     */
    public function validerChargement(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.valider_chargement')
            && $this->sameOrganization($user, $commande)
            && $commande->isChargementEnCours();
    }

    /**
     * Valider la réception (LIVRAISON_EN_COURS → LIVREE) — réservée aux commandes nécessitant une
     * réception explicite (cf. CommandeVente::requiertReceptionExplicite() : distribution_client,
     * et depuis le 06/09/2026 Grossiste + Livraison, cf. docs/grossiste.md).
     * Permission dédiée `ventes.valider_reception` depuis le 13/09/2026 (auparavant `ventes.update`,
     * ce qui donnait à tout rôle "Commercial" ayant simplement le droit de modifier une vente la
     * capacité de valider sa réception client — voir la docblock de demarrerChargement() ci-dessus
     * pour le contexte complet de cette séparation en trois permissions indépendantes).
     */
    public function validerReception(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.valider_reception')
            && $this->sameOrganization($user, $commande)
            && $commande->isLivraisonEnCours()
            && $commande->requiertReceptionExplicite();
    }

    /**
     * Enregistrer un retour de livraison (marchandise revenue avec le livreur avant tout
     * encaissement, cf. CommandeVenteRetourService). Permission dédiée `ventes.enregistrer_retour`,
     * indépendante de `ventes.update` : un retour réduit la facture, réintègre le stock et réajuste
     * la commission — une action financière à réserver aux profils qui constatent réellement la
     * livraison. Les conditions métier (LIVRAISON_EN_COURS, rien d'encaissé, vente standard) sont
     * portées par CommandeVente::isRetournable(), source unique aussi relue par le service (le
     * Gate::before de super_admin contourne cette Policy, jamais le service).
     */
    public function enregistrerRetour(User $user, CommandeVente $commande): bool
    {
        return $user->can('ventes.enregistrer_retour')
            && $this->sameOrganization($user, $commande)
            && $commande->isRetournable();
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
