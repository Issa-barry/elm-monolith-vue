<?php

namespace App\Enums;

/**
 * Motif obligatoire d'un retour de livraison (cf. CommandeVenteRetourService). Volontairement sans
 * « produit endommagé » : la marchandise retournée est réintégrée au stock disponible, une
 * marchandise abîmée doit donc être sortie du stock par un ajustement (motif « Casse »), jamais
 * masquée par un retour.
 */
enum MotifRetourCommande: string
{
    case CLIENT_ABSENT = 'client_absent';
    case CLIENT_REFUS = 'client_refus';
    case QUANTITE_NON_ACCEPTEE = 'quantite_non_acceptee';
    case ERREUR_PREPARATION = 'erreur_preparation';
    case PROBLEME_LIVRAISON = 'probleme_livraison';
    case AUTRE = 'autre';
    /**
     * Mouvement d'une régularisation après encaissement (ADR 0043) — jamais proposé pour un retour
     * de livraison (exclu de options()).
     */
    case REGULARISATION = 'regularisation';

    public function label(): string
    {
        return match ($this) {
            self::CLIENT_ABSENT => 'Client absent',
            self::CLIENT_REFUS => 'Le client a refusé la commande',
            self::QUANTITE_NON_ACCEPTEE => 'Quantité non acceptée par le client',
            self::ERREUR_PREPARATION => 'Erreur de préparation',
            self::PROBLEME_LIVRAISON => 'Problème de livraison',
            self::AUTRE => 'Autre',
            self::REGULARISATION => 'Régularisation après encaissement',
        };
    }

    /** @return array<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            array_values(array_filter(self::cases(), fn (self $case) => $case !== self::REGULARISATION)),
        );
    }
}
