<?php

namespace App\Policies;

use App\Models\FactureFournisseur;
use App\Models\User;
use App\Services\Achats\PerimetreCommandesAchat;

/**
 * Permission + périmètre du bon de commande de la facture (ADR 0022). État, quantités et
 * séparation des tâches sont vérifiés par FactureFournisseurService ; le contrôle de périmètre est
 * aussi refait explicitement par les contrôleurs (Gate::before du super administrateur).
 */
class FactureFournisseurPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('factures-fournisseurs.read');
    }

    public function view(User $user, FactureFournisseur $facture): bool
    {
        return $user->can('factures-fournisseurs.read') && $this->visible($user, $facture);
    }

    public function create(User $user): bool
    {
        return $user->can('factures-fournisseurs.create');
    }

    public function update(User $user, FactureFournisseur $facture): bool
    {
        return $user->can('factures-fournisseurs.update') && $this->visible($user, $facture);
    }

    public function valider(User $user, FactureFournisseur $facture): bool
    {
        return $user->can('factures-fournisseurs.valider') && $this->visible($user, $facture);
    }

    public function annuler(User $user, FactureFournisseur $facture): bool
    {
        return $user->can('factures-fournisseurs.annuler') && $this->visible($user, $facture);
    }

    private function visible(User $user, FactureFournisseur $facture): bool
    {
        return app(PerimetreCommandesAchat::class)->factureVisible($facture, $user);
    }
}
