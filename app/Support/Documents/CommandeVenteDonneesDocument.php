<?php

namespace App\Support\Documents;

use App\Enums\ModeTarification;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Support\PhoneFormatter;

/**
 * Données d'une commande de vente communes à ses documents (facture de vente, bon de commande de
 * vente) : blocs CLIENT / VÉHICULE et lecture des lignes. Montants et quantités repris tels que
 * tenus par le métier, jamais recalculés.
 */
final class CommandeVenteDonneesDocument
{
    /**
     * Quantité qui a servi au dernier calcul de `total_ligne` : livrée (réception ou retour),
     * sinon chargée, sinon préparée (précommande), sinon demandée.
     */
    public static function quantiteRetenue(CommandeVenteLigne $ligne): int
    {
        return (int) ($ligne->quantite_livree ?? $ligne->quantite_chargee ?? $ligne->quantite_preparee ?? $ligne->quantite_demandee ?? 0);
    }

    /** Prix du mode de tarification figé sur la commande. */
    public static function prixUnitaire(CommandeVente $commande, CommandeVenteLigne $ligne): float
    {
        return $commande->mode_tarification_snapshot === ModeTarification::PRIX_USINE
            ? (float) $ligne->prix_usine_snapshot
            : (float) $ligne->prix_vente_snapshot;
    }

    public static function designation(CommandeVenteLigne $ligne): string
    {
        return $ligne->libelle_snapshot ?? $ligne->variante?->produit?->nom ?? '—';
    }

    /**
     * CLIENT puis VÉHICULE, côte à côte. Bloc CLIENT seulement si un client est associé, avec ses
     * seules coordonnées renseignées (nom, adresse, téléphone, email) : la fiche client ne porte ni
     * raison sociale, ni RCCM, ni NIF, rien n'est donc inventé.
     *
     * @return list<array{titre: string, lignes: list<string>}>
     */
    public static function parties(?CommandeVente $commande): array
    {
        $parties = [];

        $client = $commande?->client;
        $coordonnees = $client ? array_values(array_filter([
            $client->nom_complet ?: null,
            collect([$client->adresse, $client->ville, $client->pays])->map(fn ($v) => trim((string) $v))->filter()->implode(', ') ?: null,
            filled($client->telephone) ? PhoneFormatter::display($client->telephone) : null,
            filled($client->email) ? trim($client->email) : null,
        ])) : [];
        if ($coordonnees) {
            $parties[] = ['titre' => 'CLIENT', 'lignes' => $coordonnees];
        }

        if ($vehicule = $commande?->vehicule) {
            $parties[] = ['titre' => 'VÉHICULE', 'lignes' => array_values(array_filter([
                $vehicule->nom_vehicule ?: '—',
                $vehicule->immatriculation ?: null,
            ]))];
        }

        return $parties;
    }
}
