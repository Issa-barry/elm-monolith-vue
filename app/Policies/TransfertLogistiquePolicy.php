<?php

namespace App\Policies;

use App\Enums\StatutTransfert;
use App\Models\TransfertLogistique;
use App\Models\User;

class TransfertLogistiquePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('logistique.read');
    }

    public function view(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.read')
            && $this->sameOrganization($user, $transfert);
    }

    public function create(User $user): bool
    {
        return $user->can('logistique.create');
    }

    public function update(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.update')
            && $this->sameOrganization($user, $transfert)
            && $transfert->isEditable();
    }

    public function delete(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.delete')
            && $this->sameOrganization($user, $transfert)
            && $transfert->isBrouillon();
    }

    /**
     * Avancer le statut.
     * Pour TRANSIT → RECEPTION, délègue à validerReception (site destination uniquement).
     * Pour les autres transitions, seuls logistique.update + même org suffisent.
     */
    public function avancerStatut(User $user, TransfertLogistique $transfert): bool
    {
        if (! $user->can('logistique.update')) return false;
        if (! $this->sameOrganization($user, $transfert)) return false;
        if ($transfert->isTerminal()) return false;

        // RECEPTION → CLOTURE : transition manuelle interdite, clôture automatique uniquement
        if ($transfert->statut === StatutTransfert::RECEPTION) return false;

        // TRANSIT → RECEPTION : restriction au site destination
        if ($transfert->statut === StatutTransfert::TRANSIT) {
            return $this->validerReception($user, $transfert);
        }

        return true;
    }

    /**
     * Valider la réception (TRANSIT → RECEPTION).
     * Réservé aux utilisateurs du site d'arrivée, y compris admin_entreprise.
     * Seul super_admin est exempté (autorité supra-organisation).
     */
    public function validerReception(User $user, TransfertLogistique $transfert): bool
    {
        if (! $user->can('logistique.update')) return false;
        if (! $this->sameOrganization($user, $transfert)) return false;

        // super_admin uniquement : autorité globale
        if ($user->hasRole('super_admin')) return true;

        // Tous les autres (y compris admin_entreprise) : doit être affecté au site destination
        return $transfert->site_destination_id !== null
            && $user->isAssignedToSite($transfert->site_destination_id);
    }

    /**
     * Annuler un transfert.
     * Autorisé uniquement en BROUILLON ou CHARGEMENT (pas en TRANSIT, RECEPTION, CLOTURE, ANNULE).
     * Seuls les utilisateurs du site source peuvent annuler (ou super_admin).
     */
    public function annuler(User $user, TransfertLogistique $transfert): bool
    {
        if (! $user->can('logistique.update')) return false;
        if (! $this->sameOrganization($user, $transfert)) return false;

        // Annulation interdite dès TRANSIT
        if (! in_array($transfert->statut, [StatutTransfert::BROUILLON, StatutTransfert::CHARGEMENT])) {
            return false;
        }

        // super_admin : autorité globale
        if ($user->hasRole('super_admin')) return true;

        // Tous les autres : doit être affecté au site source (initiateur du transfert)
        return $transfert->site_source_id !== null
            && $user->isAssignedToSite($transfert->site_source_id);
    }

    public function genererCommission(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.commission.verser')
            && $this->sameOrganization($user, $transfert)
            && ($transfert->isReception() || $transfert->isCloture());
    }

    public function voirCommission(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.read')
            && $this->sameOrganization($user, $transfert);
    }

    public function verserCommission(User $user, TransfertLogistique $transfert): bool
    {
        return $user->can('logistique.commission.verser')
            && $this->sameOrganization($user, $transfert)
            && ($transfert->isReception() || $transfert->isCloture());
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function sameOrganization(User $user, TransfertLogistique $transfert): bool
    {
        return $user->organization_id === $transfert->organization_id;
    }
}
