<?php

namespace App\Enums;

/**
 * Motif obligatoire d'un retour de transfert logistique (ADR 0029, cf. TransfertRetourService).
 * Volontairement sans « produit endommagé » : la marchandise retournée rejoint le stock disponible
 * du site source ; une marchandise abîmée en est ensuite sortie par un ajustement « Casse », jamais
 * masquée par un retour.
 */
enum MotifRetourTransfert: string
{
    case REFUS_RECEPTION = 'refus_reception';
    case ERREUR_CHARGEMENT = 'erreur_chargement';
    case TRANSFERT_IMPOSSIBLE = 'transfert_impossible';
    case AUTRE = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::REFUS_RECEPTION => 'Refus de réception',
            self::ERREUR_CHARGEMENT => 'Erreur de chargement',
            self::TRANSFERT_IMPOSSIBLE => 'Livraison ou transfert impossible',
            self::AUTRE => 'Autre',
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
