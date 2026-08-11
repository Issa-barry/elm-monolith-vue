<?php

namespace App\Enums;

enum ProduitType: string
{
    case MATERIEL = 'materiel';
    case SERVICE = 'service';
    case FABRICABLE = 'fabricable';
    case ACHAT_VENTE = 'achat_vente';

    public function label(): string
    {
        return match ($this) {
            self::MATERIEL => 'Matériel',
            self::SERVICE => 'Service',
            self::FABRICABLE => 'Fabricable',
            self::ACHAT_VENTE => 'Achat / Vente',
        };
    }

    public function hasStock(): bool
    {
        return $this !== self::SERVICE;
    }

    public function isVendable(): bool
    {
        return in_array('prix_vente', $this->requiredPrices(), true);
    }

    public static function vendableValues(): array
    {
        return array_column(
            array_filter(self::cases(), fn (self $c) => $c->isVendable()),
            'value'
        );
    }

    public function isAchetable(): bool
    {
        return in_array('prix_achat', $this->requiredPrices(), true);
    }

    public static function achetableValues(): array
    {
        return array_column(
            array_filter(self::cases(), fn (self $c) => $c->isAchetable()),
            'value'
        );
    }

    public function requiredPrices(): array
    {
        return match ($this) {
            self::MATERIEL => ['prix_achat'],
            self::SERVICE => [],
            self::FABRICABLE => ['prix_usine', 'prix_vente'],
            self::ACHAT_VENTE => ['prix_achat', 'prix_vente'],
        };
    }

    /**
     * Champ de coût de référence à comparer à prix_vente pour ce type (prix_vente doit lui
     * être strictement supérieur — cf. ProduitService::validerPrixSelonType()). null quand le
     * type n'a pas de règle de marge produit (MATERIEL n'est pas vendable, SERVICE n'a aucun prix).
     */
    public function champPrixReference(): ?string
    {
        return match ($this) {
            self::FABRICABLE => 'prix_usine',
            self::ACHAT_VENTE => 'prix_achat',
            self::MATERIEL, self::SERVICE => null,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases()
        );
    }
}
