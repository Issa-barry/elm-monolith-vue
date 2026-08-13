<?php

namespace App\Services\Comptabilite;

use App\Enums\EvenementComptable;
use App\Exceptions\Comptabilite\MappingComptableIndisponibleException;
use App\Models\CompteMapping;

/**
 * Résout un (compte, journal) configurés pour un événement+rôle donné.
 * Aucun numéro de compte n'est codé en dur : tout vient de compta_mappings,
 * une table de données paramétrable par organisation (règle #16 de la spec).
 */
class CompteMappingResolver
{
    /**
     * Chaîne de repli sur moyen_paiement, du plus précis au plus générique.
     *
     * Ex: moyen_paiement = "mobile_money:orange" (un wallet précis, ex: Orange
     * Money) essaie dans l'ordre :
     *   1. "mobile_money:orange"  → compte Orange Money dédié si configuré
     *   2. "mobile_money"         → compte Mobile Money générique de repli
     *   3. NULL                   → compte de trésorerie par défaut de l'événement
     *
     * Aucun opérateur n'est codé en dur ici : "orange"/"mtn"/"djomy" ne sont que
     * des étiquettes libres saisies côté métier (cf. PaiementFichePaiement.
     * moyen_paiement_detail) — le resolver ne fait que suivre le pattern
     * "type:detail", quel que soit le detail.
     */
    public function resolve(string $organizationId, EvenementComptable $evenement, string $role, ?string $moyenPaiement = null): CompteMapping
    {
        $query = CompteMapping::query()
            ->where('organization_id', $organizationId)
            ->where('evenement', $evenement->value)
            ->where('role', $role)
            ->where('actif', true);

        foreach ($this->candidats($moyenPaiement) as $candidat) {
            $mapping = (clone $query)
                ->where(fn ($q) => $candidat === null ? $q->whereNull('moyen_paiement') : $q->where('moyen_paiement', $candidat))
                ->first();

            if ($mapping) {
                return $mapping;
            }
        }

        throw MappingComptableIndisponibleException::pourRole($organizationId, $evenement->value, $role, $moyenPaiement);
    }

    /** @return list<string|null> */
    private function candidats(?string $moyenPaiement): array
    {
        if ($moyenPaiement === null) {
            return [null];
        }

        $candidats = [$moyenPaiement];

        if (str_contains($moyenPaiement, ':')) {
            $candidats[] = strstr($moyenPaiement, ':', true);
        }

        $candidats[] = null;

        return $candidats;
    }
}
