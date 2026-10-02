<?php

namespace App\Support\Commission;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtre par site d'une CommissionEnveloppePart en passant par `enveloppe.source.site` —
 * `source` est polymorphe (CommandeVente OU TransfertLogistique), qui n'ont pas de colonne
 * `site_id` commune (TransfertLogistique n'expose que site_source_id/site_destination_id), d'où
 * l'alias `site()` porté par chaque modèle source plutôt qu'un nom de colonne brut.
 *
 * N'utilise JAMAIS `whereHas('enveloppe.source.site', ...)` (chaîne à points) : Laravel résout un
 * `whereHas` multi-niveaux en repassant la même closure (avec le même tableau de segments de
 * relation restants, partagé par référence) à chaque type polymorphe testé par le MorphTo
 * intermédiaire (`source`). Dès qu'il existe 2+ source_type distincts en base (CommandeVente ET
 * TransfertLogistique), le "reset" interne de cette closure (déclenché dès que le tableau de
 * segments est vide) recharge la chaîne ENTIÈRE ("source.site") et tente de rappeler ->source()
 * sur le modèle déjà résolu (ex: TransfertLogistique), qui n'a pas cette relation :
 * BadMethodCallException "Call to undefined method ...::source()" (Sentry preprod, 13/09/2026,
 * jamais reproduit avant faute de coexistence CommandeVente/TransfertLogistique en base de test).
 * On contourne le bug en appelant whereHasMorph() nous-mêmes, avec notre PROPRE closure (jamais
 * générée par le mécanisme de chaîne à points) : chaque type polymorphe reçoit un appel
 * indépendant, sans état partagé entre les itérations.
 */
class CommissionSourceSiteFilter
{
    /** @param  Closure(Builder): mixed  $siteConstraint */
    public static function appliquer(Builder $query, Closure $siteConstraint): Builder
    {
        return $query->whereHas(
            'enveloppe',
            fn ($q) => $q->whereHasMorph(
                'source',
                ['*'],
                fn ($q2) => $q2->whereHas('site', $siteConstraint),
            ),
        );
    }
}
