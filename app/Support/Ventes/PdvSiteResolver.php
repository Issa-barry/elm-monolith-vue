<?php

namespace App\Support\Ventes;

/**
 * Site par défaut de l'utilisateur pour le PDV — même résolution que le reste de l'app (cf.
 * CommandeVenteFormBuilder::getUserSiteModel()), jamais dupliquée en logique différente. Extrait
 * de l'ancien `PdvController`, partagé par `Ventes\{Index,Checkout}PdvController`.
 */
final class PdvSiteResolver
{
    /**
     * Retourne null plutôt que d'aborter : `IndexPdvController` reste consultable (sans
     * filtrage de stock) même pour un utilisateur sans site, `CheckoutPdvController` garde son
     * propre abort_if explicite.
     */
    public static function defaultSiteId(): ?string
    {
        $user = auth()->user();

        return $user->sites()->wherePivot('is_default', true)->value('sites.id')
            ?? $user->sites()->value('sites.id');
    }
}
