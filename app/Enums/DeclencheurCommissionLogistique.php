<?php

namespace App\Enums;

/**
 * Détermine QUAND une commission logistique est générée — jamais comment elle
 * est calculée (cf. CommissionLogistiqueService::calculerMontant()/creerParts(),
 * seule source de vérité du calcul). Paramétrable par organisation via
 * Parametre::CLE_VENTES_DECLENCHEUR_COMMISSION_LOGISTIQUE, lu et appliqué par
 * CommissionTriggerService.
 */
enum DeclencheurCommissionLogistique: string
{
    /**
     * Dès que le chargement du transfert est validé (départ, CHARGEMENT → TRANSIT).
     * La quantité reçue n'étant pas encore connue à ce stade, le calcul se base sur
     * la quantité chargée — le montant est figé à cet instant et n'est jamais
     * recalculé rétroactivement en cas d'écart constaté à la réception.
     */
    case CHARGEMENT_VALIDE = 'chargement_valide';

    /** Uniquement lorsque la réception est validée par un administrateur (comportement historique). */
    case RECEPTION_EFFECTUEE = 'reception_effectuee';

    public function label(): string
    {
        return match ($this) {
            self::CHARGEMENT_VALIDE => 'À la validation du chargement',
            self::RECEPTION_EFFECTUEE => 'À la réception',
        };
    }

    /** @return array<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
