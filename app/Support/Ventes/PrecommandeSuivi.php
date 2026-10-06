<?php

namespace App\Support\Ventes;

use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use Illuminate\Support\Collection;

/**
 * Indicateurs opérationnels de la page Précommandes (ADR 0019) : où en est le cycle, et ce qui
 * demande une action. Aucun montant ici — le suivi financier reste sur les factures et les ventes.
 * Chaque indicateur porte les statuts qu'il couvre : la page s'en sert comme filtre rapide, sans
 * redéfinir les regroupements côté client.
 */
final class PrecommandeSuivi
{
    /** Ni réalisée (retirée, livrée, clôturée), ni annulée, ni retournée. */
    public const EN_COURS = [
        StatutCommandeVente::RESERVEE,
        StatutCommandeVente::A_PREPARER,
        StatutCommandeVente::PREPAREE,
        StatutCommandeVente::A_CHARGER,
        StatutCommandeVente::CHARGEMENT_EN_COURS,
        StatutCommandeVente::LIVRAISON_EN_COURS,
    ];

    /** Préparation à lancer ou à valider. */
    public const A_PREPARER = [
        StatutCommandeVente::RESERVEE,
        StatutCommandeVente::A_PREPARER,
    ];

    /** Chargée, livraison pas encore confirmée (D13 : chargement ≠ livraison). */
    public const EN_LIVRAISON = [
        StatutCommandeVente::LIVRAISON_EN_COURS,
    ];

    /**
     * @param  Collection<int, CommandeVente>  $precommandes
     * @return array<string, array{nombre: int, statuts: list<string>}>
     */
    public static function indicateurs(Collection $precommandes): array
    {
        $compter = fn (array $statuts) => [
            'nombre' => $precommandes->filter(fn (CommandeVente $c) => in_array($c->statut, $statuts, true))->count(),
            'statuts' => array_map(fn (StatutCommandeVente $s) => $s->value, $statuts),
        ];

        return [
            'en_cours' => $compter(self::EN_COURS),
            'a_preparer' => $compter(self::A_PREPARER),
            'en_livraison' => $compter(self::EN_LIVRAISON),
            'en_retard' => [
                'nombre' => $precommandes->filter(fn (CommandeVente $c) => $c->isEnRetard())->count(),
                'statuts' => [],
            ],
        ];
    }
}
