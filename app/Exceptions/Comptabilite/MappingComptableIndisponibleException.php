<?php

namespace App\Exceptions\Comptabilite;

use RuntimeException;

/**
 * Levée quand un événement métier ne peut pas être comptabilisé faute de
 * mapping compte configuré pour l'organisation. Volontairement bruyante côté
 * moteur comptable — mais les points d'accroche métier (Observers/listeners)
 * doivent l'attraper et ne jamais laisser une comptabilité non configurée
 * bloquer une opération métier (mode shadow), cf. règle #26 de la spec.
 */
class MappingComptableIndisponibleException extends RuntimeException
{
    public static function pourRole(string $organizationId, string $evenement, string $role, ?string $moyenPaiement = null): self
    {
        $suffixe = $moyenPaiement ? " (moyen de paiement: {$moyenPaiement})" : '';

        return new self("Aucun compte mappé pour l'événement « {$evenement} », rôle « {$role} »{$suffixe} (organisation {$organizationId}). Configurez compte_mappings avant de comptabiliser cet événement.");
    }
}
