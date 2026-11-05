<?php

namespace App\Services\Client\Operations;

use App\Models\Client;
use App\Models\CommandeVente;

/**
 * Données d'une vente visibles selon le lien du compte : le prix est réservé au client, et les
 * coordonnées au client et au livreur pendant la livraison. Le propriétaire n'a ni l'un ni l'autre.
 */
final class PresentationOperation
{
    /** @param  list<string>  $liens */
    public static function voitLesPrix(array $liens): bool
    {
        return in_array(OperationsCompte::LIEN_CLIENT, $liens, true);
    }

    /**
     * Le client garde ses propres coordonnées ; le livreur ne les voit que pendant la livraison.
     *
     * @param  list<string>  $liens
     */
    public static function voitLeContact(CommandeVente $vente, array $liens): bool
    {
        return in_array(OperationsCompte::LIEN_CLIENT, $liens, true)
            || (in_array(OperationsCompte::LIEN_LIVREUR, $liens, true) && $vente->isLivraisonEnCours());
    }

    public static function adresse(?Client $client): ?string
    {
        return implode(', ', array_filter([$client?->adresse, $client?->ville])) ?: null;
    }
}
