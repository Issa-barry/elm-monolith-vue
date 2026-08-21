<?php

namespace App\Services\Rh;

use App\Models\FonctionRh;
use Illuminate\Support\Str;

/**
 * Génère le trinôme d'une fonction RH depuis son libellé, et vérifie l'unicité (code, libellé)
 * strictement par organisation — une fonction RH n'existe jamais sans organisation (cf.
 * FonctionRh::organization_id non nullable, décision finale du 2026-08-21 : aucune fonction
 * système/partagée). Mirroring de RoleNamingService::uniqueTrinome() — algorithme identique,
 * dupliqué volontairement plutôt que de coupler FonctionRh à RoleNamingService (deux référentiels
 * distincts). Contrairement aux rôles, une fonction RH n'a pas de "nom technique" Spatie à
 * générer — seuls `libelle` et `code` existent.
 */
class FonctionRhNamingService
{
    private const ELISION_PATTERN = '/\b(?:qu|[dlnstjcm])[\'’]/ui';

    /**
     * Trinôme — initiales des mots significatifs pour un libellé à plusieurs mots ("Président
     * directeur général" → "PDG"), ou les 3 premières lettres pour un libellé à un seul mot
     * ("Contrôleur" → "CON"). En cas de collision, étend progressivement avec les lettres
     * suivantes du dernier mot, puis un suffixe numérique en dernier recours.
     */
    public function uniqueTrinome(string $libelle, string $organizationId, ?string $ignoreId = null): string
    {
        $words = $this->significantWords($libelle);
        if ($words === []) {
            return $this->uniqueNumericCode('FON', $organizationId, $ignoreId);
        }

        $base = count($words) === 1
            ? Str::upper(Str::substr($words[0], 0, 3))
            : Str::upper(implode('', array_map(fn ($w) => Str::substr($w, 0, 1), $words)));

        if (! $this->codeTaken($base, $organizationId, $ignoreId)) {
            return $base;
        }

        $lastWord = Str::upper(end($words));
        $lastWordLength = Str::length($lastWord);
        $prefix = count($words) === 1 ? '' : Str::upper(implode('', array_map(fn ($w) => Str::substr($w, 0, 1), array_slice($words, 0, -1))));

        for ($extra = 2; $extra <= $lastWordLength; $extra++) {
            $candidate = $prefix.Str::substr($lastWord, 0, $extra);
            if (! $this->codeTaken($candidate, $organizationId, $ignoreId)) {
                return $candidate;
            }
        }

        return $this->uniqueNumericCode($base, $organizationId, $ignoreId);
    }

    /** Normalise un trinôme saisi manuellement — ne garantit pas l'unicité, cf. codeTaken(). */
    public function normalizeTrinome(string $code): string
    {
        return Str::upper(trim($code));
    }

    public function codeTaken(string $code, string $organizationId, ?string $ignoreId = null): bool
    {
        return FonctionRh::where('organization_id', $organizationId)
            ->where('code', $code)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    public function libelleTaken(string $libelle, string $organizationId, ?string $ignoreId = null): bool
    {
        return FonctionRh::where('organization_id', $organizationId)
            ->where('libelle', $libelle)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    private function uniqueNumericCode(string $base, string $organizationId, ?string $ignoreId): string
    {
        $suffix = 2;
        do {
            $candidate = "{$base}{$suffix}";
            $suffix++;
        } while ($this->codeTaken($candidate, $organizationId, $ignoreId));

        return $candidate;
    }

    /**
     * @return list<string>
     */
    private function significantWords(string $libelle): array
    {
        $withoutElisions = preg_replace(self::ELISION_PATTERN, '', $libelle) ?? $libelle;
        $ascii = Str::ascii($withoutElisions);

        preg_match_all('/[A-Za-z]+/', $ascii, $matches);

        return array_values($matches[0]);
    }
}
