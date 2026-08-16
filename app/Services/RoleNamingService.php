<?php

namespace App\Services;

use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Dérive le nom technique Spatie et le trinôme d'un rôle depuis son libellé saisi par
 * l'utilisateur — seule source de vérité pour cette génération (RoleController ne fait
 * qu'appeler ces méthodes, jamais de logique de normalisation dupliquée ailleurs).
 *
 * Le nom technique n'est généré qu'une fois, à la création (cf. RoleController::store()) — il
 * n'est jamais régénéré à l'édition, même si le libellé change ensuite (voir docblock de
 * RoleController pour la justification : plusieurs Policies testent des noms de rôle en dur).
 */
class RoleNamingService
{
    /**
     * Élisions françaises (d', l', qu', n', s', t', j', c', m') : "Chef d'agence" doit donner
     * "chef_agence", pas "chef_d_agence" — le "d'" est retiré comme un tout avant la
     * slugification, jamais traité comme un mot à part entière.
     */
    private const ELISION_PATTERN = '/\b(?:qu|[dlnstjcm])[\'’]/ui';

    /**
     * Nom technique Spatie — minuscules, underscore, sans accent ni ponctuation. Ne garantit
     * l'unicité qu'associée à uniqueTechnicalName() ci-dessous.
     */
    public function technicalName(string $label): string
    {
        $withoutElisions = preg_replace(self::ELISION_PATTERN, '', $label) ?? $label;
        $slug = Str::slug($withoutElisions, '_');

        // Filet de sécurité : un libellé constitué uniquement de caractères non translittérables
        // (emoji, symboles...) donnerait un slug vide — jamais un name vide en base.
        return $slug !== '' ? $slug : 'role_'.Str::lower(Str::random(6));
    }

    /**
     * Contrairement au trinôme (cf. uniqueTrinome()), une collision sur le nom technique n'est
     * JAMAIS résolue automatiquement (pas de suffixe "_2") — deux libellés qui normalisent vers
     * le même nom technique ("Chef d'agence" et "CHEF D'AGENCE") désignent fonctionnellement le
     * même rôle, donc la création doit être refusée avec un message clair plutôt que de créer un
     * doublon silencieux (cf. RoleController::store()/update(), qui appelle cette méthode avant
     * toute écriture).
     *
     * @param  string|null  $organizationId  null = rôle système (partagé), sinon périmètre de
     *                                       l'unicité — deux organisations peuvent avoir le même
     *                                       nom technique, jamais deux fois la même organisation.
     * @param  int|null  $ignoreRoleId  exclu de la recherche de collision (édition d'un rôle).
     */
    public function technicalNameTaken(string $name, ?string $organizationId, ?int $ignoreRoleId = null): bool
    {
        return Role::where('organization_id', $organizationId)
            ->where('name', $name)
            ->when($ignoreRoleId, fn ($q) => $q->where('id', '!=', $ignoreRoleId))
            ->exists();
    }

    /**
     * Trinôme — initiales des mots significatifs pour un libellé à plusieurs mots ("Président
     * directeur général" → "PDG", "Chef d'agence" → "CA" une fois l'élision retirée), ou les 3
     * premières lettres pour un libellé à un seul mot ("Contrôleur" → "CON"). En cas de collision
     * dans l'organisation, étend progressivement avec les lettres suivantes du dernier mot
     * ("Responsable Commercial" → "RC", puis "RCO" si "RC" existe déjà) plutôt que de fusionner
     * silencieusement deux rôles différents ; un suffixe numérique reste le dernier recours.
     */
    public function uniqueTrinome(string $label, ?string $organizationId, ?int $ignoreRoleId = null): string
    {
        $words = $this->significantWords($label);
        if ($words === []) {
            return $this->uniqueNumericCode('ROLE', $organizationId, $ignoreRoleId);
        }

        $base = count($words) === 1
            ? Str::upper(Str::substr($words[0], 0, 3))
            : Str::upper(implode('', array_map(fn ($w) => Str::substr($w, 0, 1), $words)));

        if (! $this->codeTaken($base, $organizationId, $ignoreRoleId)) {
            return $base;
        }

        $lastWord = Str::upper(end($words));
        $lastWordLength = Str::length($lastWord);
        $prefix = count($words) === 1 ? '' : Str::upper(implode('', array_map(fn ($w) => Str::substr($w, 0, 1), array_slice($words, 0, -1))));

        // Étend avec 2, 3, 4... lettres du dernier mot au lieu d'une seule — ex: "RC" → "RCO"
        // (2 lettres de "Commercial") plutôt que de recommencer depuis 1 lettre (qui redonnerait
        // "RC").
        for ($extra = 2; $extra <= $lastWordLength; $extra++) {
            $candidate = $prefix.Str::substr($lastWord, 0, $extra);
            if (! $this->codeTaken($candidate, $organizationId, $ignoreRoleId)) {
                return $candidate;
            }
        }

        return $this->uniqueNumericCode($base, $organizationId, $ignoreRoleId);
    }

    /**
     * Normalise un trinôme saisi manuellement (majuscules, sans espace) — ne garantit pas
     * l'unicité, à valider séparément (cf. RoleController, qui doit distinguer "déjà pris par un
     * autre rôle" — erreur bloquante sur une saisie manuelle — de la génération automatique
     * ci-dessus, qui elle ne doit jamais échouer).
     */
    public function normalizeTrinome(string $code): string
    {
        return Str::upper(trim($code));
    }

    public function trinomeTaken(string $code, ?string $organizationId, ?int $ignoreRoleId = null): bool
    {
        return $this->codeTaken($this->normalizeTrinome($code), $organizationId, $ignoreRoleId);
    }

    private function codeTaken(string $code, ?string $organizationId, ?int $ignoreRoleId): bool
    {
        return Role::where('organization_id', $organizationId)
            ->where('code', $code)
            ->when($ignoreRoleId, fn ($q) => $q->where('id', '!=', $ignoreRoleId))
            ->exists();
    }

    private function uniqueNumericCode(string $base, ?string $organizationId, ?int $ignoreRoleId): string
    {
        $suffix = 2;
        do {
            $candidate = "{$base}{$suffix}";
            $suffix++;
        } while ($this->codeTaken($candidate, $organizationId, $ignoreRoleId));

        return $candidate;
    }

    /**
     * @return list<string>
     */
    private function significantWords(string $label): array
    {
        $withoutElisions = preg_replace(self::ELISION_PATTERN, '', $label) ?? $label;
        $ascii = Str::ascii($withoutElisions);

        preg_match_all('/[A-Za-z]+/', $ascii, $matches);

        return array_values($matches[0]);
    }
}
