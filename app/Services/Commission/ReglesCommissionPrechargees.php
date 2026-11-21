<?php

namespace App\Services\Commission;

use App\Models\CommissionRegle;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Règles d'une cible chargées en une seule requête pour plusieurs processus à une même date, puis
 * résolues en mémoire. Même résultat que CommissionRegleResolver::resolve() (mêmes filtres SQL,
 * même ordre, même ordre de recherche), sans une requête par niveau de portée : pour les écrans
 * qui résolvent des centaines de combinaisons (colonne « Partages » de la liste des véhicules).
 *
 * Deux règles du même niveau à égalité de date d'effet et de création (donnée incohérente) : l'ordre
 * SQL entre elles n'est pas défini, ce chargement ne tranche donc pas (tranche() = false) et
 * l'appelant laisse le résolveur SQL décider, exactement comme avant.
 */
final class ReglesCommissionPrechargees
{
    /** @var array<string, list<CommissionRegle>> "processus|portée|scope_id|type" => règles, ordre de la requête conservé */
    private array $parNiveau = [];

    /**
     * @param  list<string>  $processusIds
     * @param  Collection<int, CommissionRegle>  $regles
     */
    private function __construct(
        private readonly string $organizationId,
        private readonly array $processusIds,
        private readonly string $cibleType,
        private readonly string $date,
        Collection $regles,
    ) {
        foreach ($regles as $regle) {
            $this->parNiveau[self::cle($regle->processus_id, $regle->scope_type->value, $regle->scope_id, $regle->type_vehicule_id)][] = $regle;
        }
    }

    /** @param  iterable<string>  $processusIds */
    public static function charger(string $organizationId, iterable $processusIds, string $cibleType, CarbonInterface $date): self
    {
        $ids = collect($processusIds)->filter()->map(fn ($id) => (string) $id)->unique()->values();
        $regles = $ids->isEmpty()
            ? collect()
            : CommissionRegleResolver::requeteApplicables($organizationId, $cibleType, $date)->whereIn('processus_id', $ids)->get();

        return new self($organizationId, $ids->all(), $cibleType, $date->toDateString(), $regles);
    }

    /** Vrai seulement pour une demande que ce chargement peut trancher seul (sinon : le résolveur SQL). */
    public function couvre(string $organizationId, string $processusId, string $cibleType, CarbonInterface $date): bool
    {
        return $organizationId === $this->organizationId
            && $cibleType === $this->cibleType
            && $date->toDateString() === $this->date
            && in_array($processusId, $this->processusIds, true);
    }

    /** Faux quand le niveau qui décide contient deux règles à égalité d'ordre (cf. docblock de la classe). */
    public function tranche(string $processusId, ?string $varianteId, ?string $produitId, ?string $categorieId, ?string $typeVehiculeId = null): bool
    {
        $regles = $this->niveauDecisif($processusId, $varianteId, $produitId, $categorieId, $typeVehiculeId);

        return count($regles) < 2
            || $regles[0]->getRawOriginal('effective_from') !== $regles[1]->getRawOriginal('effective_from')
            || $regles[0]->getRawOriginal('created_at') !== $regles[1]->getRawOriginal('created_at');
    }

    public function resoudre(string $processusId, ?string $varianteId, ?string $produitId, ?string $categorieId, ?string $typeVehiculeId = null): ?CommissionRegle
    {
        return $this->niveauDecisif($processusId, $varianteId, $produitId, $categorieId, $typeVehiculeId)[0] ?? null;
    }

    /** @return list<CommissionRegle> règles du premier niveau de recherche qui en contient */
    private function niveauDecisif(string $processusId, ?string $varianteId, ?string $produitId, ?string $categorieId, ?string $typeVehiculeId): array
    {
        foreach (CommissionRegleResolver::ordreDeRecherche($varianteId, $produitId, $categorieId, $typeVehiculeId) as [$scopeType, $scopeId, $type]) {
            $regles = $this->parNiveau[self::cle($processusId, $scopeType->value, $scopeId, $type)] ?? [];
            if ($regles !== []) {
                return $regles;
            }
        }

        return [];
    }

    private static function cle(string $processusId, string $scopeType, ?string $scopeId, ?string $typeVehiculeId): string
    {
        // NULL codé à part : jamais confondu avec une valeur, comme whereNull() côté SQL.
        return $processusId.'|'.$scopeType.'|'.($scopeId ?? "\0").'|'.($typeVehiculeId ?? "\0");
    }
}
