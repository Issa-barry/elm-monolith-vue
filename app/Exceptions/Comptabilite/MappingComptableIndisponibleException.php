<?php

namespace App\Exceptions\Comptabilite;

use RuntimeException;

/**
 * Levée quand un événement métier ne peut pas être comptabilisé faute de
 * mapping compte configuré pour l'organisation.
 *
 * Pour un événement qui déplace de la trésorerie réelle (paiement de fiche,
 * de salaire, dépense validée, encaissement) : NE DOIT PLUS être avalée en
 * mode shadow — le point d'accroche métier doit la laisser propager pour
 * annuler l'opération dans la même transaction (revue Codex du 2026-08-22,
 * corrige la règle #26 d'origine). Pour un événement qui ne touche aucun
 * compte de trésorerie (fiche_*_validee, vente_facturee — engagement/dette
 * seulement) : le mode shadow reste approprié, le blast radius d'un blocage
 * (chargement, validation de période) étant disproportionné par rapport au
 * bénéfice, cf. docblocks de CommandeVenteService/PaiementPeriodeController.
 */
class MappingComptableIndisponibleException extends RuntimeException
{
    public static function pourRole(string $organizationId, string $evenement, string $role, ?string $moyenPaiement = null): self
    {
        $suffixe = $moyenPaiement ? " (moyen de paiement: {$moyenPaiement})" : '';

        return new self("Aucun compte mappé pour l'événement « {$evenement} », rôle « {$role} »{$suffixe} (organisation {$organizationId}). Configurez compta_mappings avant de comptabiliser cet événement.");
    }
}
