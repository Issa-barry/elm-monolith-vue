<?php

namespace App\Services\Commission;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Moteur unique et partagé de répartition d'une enveloppe collective entre les
 * membres actifs d'un CommissionGroupe — jamais dupliqué par type de groupe
 * (équipe livraison, équipe dépôt...). Corrige deux lacunes identifiées dans
 * l'ancien moteur (cf. audit) : validation somme=100% appliquée uniformément
 * (pas seulement côté vente), et reliquat d'arrondi assigné explicitement
 * (pas d'écart silencieux).
 */
class CommissionRepartitionEngine
{
    private const TOLERANCE = 0.01;

    /**
     * @throws InvalidArgumentException si la composition est vide ou si la somme
     *                                  des parts ne totalise pas 100 % (± tolérance)
     */
    public static function validerSomme100(Collection $membres): void
    {
        if ($membres->isEmpty()) {
            throw new InvalidArgumentException('Aucun membre actif dans le groupe.');
        }

        foreach ($membres as $membre) {
            $part = (float) $membre->part_pourcentage;
            if ($part < 0 || $part > 100) {
                throw new InvalidArgumentException(
                    "La part d'un membre ({$part} %) doit être comprise entre 0 et 100."
                );
            }
        }

        $somme = round((float) $membres->sum('part_pourcentage'), 10);
        if (abs($somme - 100.0) > self::TOLERANCE) {
            throw new InvalidArgumentException(
                "La somme des parts du groupe ({$somme} %) doit être égale à 100 %."
            );
        }
    }

    /**
     * Répartit un montant total entre les membres, en assignant explicitement
     * le reliquat d'arrondi au dernier membre (ordre stable par id) — garantit
     * SUM(parts) === montantTotal au centime près, toujours.
     *
     * @return array<int, array{beneficiaire_type:string, beneficiaire_id:string, part_pourcentage:float, montant:float}>
     *
     * @throws InvalidArgumentException
     */
    public static function repartir(float $montantTotal, Collection $membres): array
    {
        self::validerSomme100($membres);

        $tries = $membres->sortBy(fn ($m) => (string) $m->id)->values();
        $dernierIndex = $tries->count() - 1;
        $montantTotal = round($montantTotal, 2);
        $reliquat = $montantTotal;

        $parts = [];

        foreach ($tries as $index => $membre) {
            if ($index === $dernierIndex) {
                $montant = $reliquat;
            } else {
                $montant = round($montantTotal * (float) $membre->part_pourcentage / 100, 2);
                $reliquat = round($reliquat - $montant, 2);
            }

            $parts[] = [
                'beneficiaire_type' => $membre->beneficiaire_type,
                'beneficiaire_id' => $membre->beneficiaire_id,
                'part_pourcentage' => (float) $membre->part_pourcentage,
                'montant' => $montant,
            ];
        }

        return $parts;
    }
}
