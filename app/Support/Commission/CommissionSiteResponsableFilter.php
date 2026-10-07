<?php

namespace App\Support\Commission;

use Illuminate\Database\Eloquent\Builder;

/**
 * Filtre une requête de CommissionEnveloppePart sur l'agence qui PAIE la commission — traduction
 * SQL exacte de CommissionEnveloppe::siteResponsableId() : site actuel du véhicule de l'opération,
 * ou, si ce véhicule n'est rattaché à aucun site, site de l'opération (relation `site()` de la
 * source : site de la commande, agence source du transfert).
 *
 * À ne pas confondre avec CommissionSourceSiteFilter, qui filtre sur le site où l'opération a eu
 * lieu (commission de la cible Site). Même précaution que lui : whereHasMorph() manuel, jamais
 * une chaîne à points à travers le MorphTo `source`.
 */
class CommissionSiteResponsableFilter
{
    /** @param  array<int, string>  $siteIds */
    public static function appliquer(Builder $query, array $siteIds): Builder
    {
        return $query->whereHas(
            'enveloppe',
            fn ($q) => $q->whereHasMorph(
                'source',
                ['*'],
                fn ($source) => $source->where(fn ($w) => $w
                    ->whereHas('vehicule', fn ($v) => $v->whereIn('site_id', $siteIds))
                    ->orWhere(fn ($repli) => $repli
                        ->whereDoesntHave('vehicule', fn ($v) => $v->whereNotNull('site_id'))
                        ->whereHas('site', fn ($s) => $s->whereIn('id', $siteIds)))),
            ),
        );
    }
}
