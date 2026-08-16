<?php

namespace App\Enums;

/**
 * Détermine QUAND une commission de vente naît — jamais dans quel statut :
 * dans les deux cas elle naît toujours CREEE (cf. CommissionGenerator), et ne
 * devient IMPAYE(E) qu'à la validation de la période de paiement qui la
 * couvre (cf. CommissionAdjustmentService::activerCommissionsCreees()).
 * Paramétrable par organisation via Parametre::CLE_DECLENCHEUR_COMMISSION_VENTE,
 * lu et appliqué par CommissionTriggerService.
 */
enum DeclencheurCommissionVente: string
{
    /** À la validation réelle du chargement, sur les quantités chargées (comportement historique). */
    case CHARGEMENT_VALIDE = 'chargement_valide';

    /** Uniquement lorsque la facture atteint le statut « Payée » (encaissement complet). */
    case FACTURE_ENCAISSEE = 'facture_encaissee';

    public function label(): string
    {
        return match ($this) {
            self::CHARGEMENT_VALIDE => 'À la validation du chargement',
            self::FACTURE_ENCAISSEE => 'À l\'encaissement de la facture',
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
