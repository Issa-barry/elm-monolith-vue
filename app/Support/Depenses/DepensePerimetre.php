<?php

namespace App\Support\Depenses;

use App\Models\Depense;
use App\Models\Site;
use App\Models\User;
use App\Services\DroitCreationDepenseService;
use App\Services\SiteScopeService;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Périmètre de CONSULTATION des dépenses (docs/depenses-visibilite.md, ADR 0065) — seul point qui
 * décide quelles dépenses un utilisateur voit : liste, statistiques, export, impression, fiche,
 * historique, suggestions, dépenses affichées dans les fiches agent, véhicule et propriétaire, et
 * tableau de bord Stat-Dépenses.
 *
 * - Toutes les agences : vision de toutes les agences (User::voitToutesLesAgences(), ADR 0025/0034)
 *   ou permission du rôle `depenses.lecture_toutes_agences`. Seul ce cas voit les dépenses sans agence.
 * - Sinon : ses agences de rattachement (`user_sites`, via SiteScopeService) + les agences où son rôle
 *   peut valider (Paramètres → Dépenses, mêmes critères que DepensePolicy::valider) + les dépenses
 *   qu'il a lui-même saisies. La création étant déjà limitée à ses agences de rattachement
 *   (StoreDepenseController), elle n'élargit rien.
 *
 * Les agences demandées par le navigateur (`site_ids[]`) ne font jamais que restreindre ce périmètre.
 */
final class DepensePerimetre
{
    public const PERMISSION_TOUTES_AGENCES = 'depenses.lecture_toutes_agences';

    /** @var array<string, list<string>> */
    private array $cacheSites = [];

    public function __construct(
        private readonly SiteScopeService $siteScope,
        private readonly DroitCreationDepenseService $droits,
    ) {}

    public function voitToutesLesAgences(User $user): bool
    {
        return $user->voitToutesLesAgences() || $user->can(self::PERMISSION_TOUTES_AGENCES);
    }

    /**
     * Agences consultables hors vision toutes agences.
     *
     * @return list<string>
     */
    public function siteIds(User $user): array
    {
        return $this->cacheSites[$user->id] ??= $this->calculerSiteIds($user);
    }

    /**
     * Restreint une requête de dépenses au périmètre de l'utilisateur.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function appliquer(Builder $query, User $user, string $colonneSite = 'site_id', string $colonneAuteur = 'user_id'): Builder
    {
        if ($this->voitToutesLesAgences($user)) {
            return $query;
        }

        $siteIds = $this->siteIds($user);

        return $query->where(function ($w) use ($siteIds, $colonneSite, $colonneAuteur, $user) {
            $w->where($colonneAuteur, $user->id);
            if ($siteIds !== []) {
                $w->orWhereIn($colonneSite, $siteIds);
            }
        });
    }

    /**
     * Agences du filtre Agence effectivement appliquées : la sélection du navigateur croisée avec le
     * périmètre. null = aucune sélection (tout le périmètre) ; liste vide = aucune agence autorisée
     * demandée (aucun résultat).
     *
     * @param  array<int, mixed>  $demandes
     * @return list<string>|null
     */
    public function sitesFiltres(User $user, array $demandes): ?array
    {
        $demandes = array_values(array_unique(array_filter(array_map('strval', $demandes))));
        if ($demandes === []) {
            return null;
        }

        $autorises = array_map(fn (array $s) => $s['id'], $this->sitesProposes($user));

        return array_values(array_intersect($demandes, $autorises));
    }

    public function peutVoir(User $user, Depense $depense): bool
    {
        if ($user->organization_id !== $depense->organization_id) {
            return false;
        }

        if ($this->voitToutesLesAgences($user) || $depense->user_id === $user->id) {
            return true;
        }

        return $depense->site_id !== null && in_array($depense->site_id, $this->siteIds($user), true);
    }

    /**
     * Options du filtre Agence : toutes les agences de l'organisation en vision toutes agences, ses
     * agences consultables sinon.
     *
     * @return list<array{id: string, nom: string}>
     */
    public function sitesProposes(User $user): array
    {
        $toutes = $this->voitToutesLesAgences($user);

        return Site::query()
            ->where('organization_id', $user->organization_id)
            ->when(! $toutes, fn ($q) => $q->whereIn('id', $this->siteIds($user)))
            ->orderBy('nom')
            ->get(['id', 'nom'])
            ->map(fn (Site $s) => ['id' => (string) $s->id, 'nom' => $s->nom])
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function calculerSiteIds(User $user): array
    {
        $ids = $this->siteScope->accessibleSiteIds($user)->map(fn ($id) => (string) $id)->all();

        if ($user->can('depenses.update')) {
            $droit = $this->droits->droitValidationPour($user, $user->organization_id);
            if ($droit?->isToutesAgences()) {
                $ids = Site::where('organization_id', $user->organization_id)->pluck('id')->map(fn ($id) => (string) $id)->all();
            } elseif ($droit?->isAgencesSelectionnees()) {
                $ids = [...$ids, ...array_map('strval', $droit->sites ?? [])];
            }
        }

        return array_values(array_unique($ids));
    }
}
