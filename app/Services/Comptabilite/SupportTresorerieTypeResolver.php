<?php

namespace App\Services\Comptabilite;

use App\Enums\TypeSupportTresorerie;
use App\Models\CompteMapping;
use Illuminate\Support\Collection;

/**
 * Déduit, pour chaque compte comptable marqué "tresorerie" dans compta_mappings,
 * le type de support (Caisse/Banque/Mobile Money) auquel il correspond — à partir
 * du moyen_paiement associé, jamais d'un numéro de compte codé en dur. Empêche
 * qu'un compte Mobile Money soit sélectionné pour un support Caisse (revue du
 * 2026-08-22 — la ligne "Sonfonia" avait été créée avec le type Caisse et le
 * compte 561300 Mobile Money Djomy, car l'ancien dropdown ne filtrait rien).
 */
class SupportTresorerieTypeResolver
{
    /** @return Collection<string, TypeSupportTresorerie> compte_comptable_id => type déduit */
    public function typesParCompte(string $organizationId): Collection
    {
        return CompteMapping::where('organization_id', $organizationId)
            ->where('role', 'tresorerie')
            ->get(['compte_comptable_id', 'moyen_paiement'])
            ->reduce(function (Collection $map, CompteMapping $mapping) {
                if ($map->has($mapping->compte_comptable_id)) {
                    return $map;
                }

                $type = TypeSupportTresorerie::fromMoyenPaiement($mapping->moyen_paiement);
                if ($type !== null) {
                    $map->put($mapping->compte_comptable_id, $type);
                }

                return $map;
            }, collect());
    }

    public function typePourCompte(string $organizationId, string $compteComptableId): ?TypeSupportTresorerie
    {
        return $this->typesParCompte($organizationId)->get($compteComptableId);
    }
}
