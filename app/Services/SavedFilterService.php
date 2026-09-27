<?php

namespace App\Services;

use App\Models\SavedFilter;
use App\Models\Site;
use App\Models\User;
use App\Support\SavedFilters\SavedFilterScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Moteur commun des vues enregistrées (« Mes vues ») : stockage, partage, vue par défaut et
 * résolution des agences. Les listes se déclarent dans SavedFilterScopes, jamais ici.
 */
final class SavedFilterService
{
    public function filterKeys(User $user, string $scope): array
    {
        $config = $this->config($user, $scope);

        return [...array_keys($config['criteria']), ...($config['sites'] ? ['site_ids', 'site_scope'] : [])];
    }

    public function canShare(User $user, string $scope): bool
    {
        return $user->can($this->config($user, $scope)['share']);
    }

    private function config(User $user, string $scope): array
    {
        $scopes = SavedFilterScopes::all($user);
        abort_unless(isset($scopes[$scope]), 404);

        return $scopes[$scope];
    }

    public function owned(User $user, string $scope): Builder
    {
        [$ability, $model] = $this->config($user, $scope)['authorize'];
        Gate::forUser($user)->authorize($ability, $model);
        abort_unless($user->organization_id, 403);

        return SavedFilter::query()
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('scope', $scope);
    }

    public function visible(User $user, string $scope): Builder
    {
        $this->owned($user, $scope); // Permission de la liste, avant toute lecture.

        return SavedFilter::query()->where('organization_id', $user->organization_id)
            ->where('scope', $scope)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('visibility', 'shared'));
    }

    public function defaultId(User $user, string $scope): ?string
    {
        return DB::table('saved_filter_preferences')->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)->where('scope', $scope)->value('saved_filter_id');
    }

    public function setDefault(User $user, string $scope, ?string $id): void
    {
        $query = $this->visible($user, $scope);
        if ($id !== null) {
            $query->findOrFail($id);
        }
        DB::table('saved_filter_preferences')->updateOrInsert(
            ['organization_id' => $user->organization_id, 'user_id' => $user->id, 'scope' => $scope],
            ['saved_filter_id' => $id],
        );
    }

    public function payload(SavedFilter $view, User $user): array
    {
        return [
            ...$view->only(['id', 'name', 'visibility', 'filters']),
            'owner_name' => $view->user?->name,
            'can_manage' => $view->user_id === $user->id,
        ];
    }

    /** Applique une vue explicite, ou la préférence personnelle sur une URL sans filtres. */
    public function applyToRequest(Request $request, string $scope): ?array
    {
        $user = $request->user();
        $keys = $this->filterKeys($user, $scope);
        $explicit = $request->filled('saved_view');
        $manual = $request->hasAny([...$keys, 'all']);
        $id = $explicit ? $request->string('saved_view')->toString() : (! $manual ? $this->defaultId($user, $scope) : null);
        if (! $id) {
            return null;
        }
        $view = $this->visible($user, $scope)->find($id);
        if (! $view) {
            abort_if($explicit, 404);

            return null;
        }
        $filters = $view->filters;
        if (($filters['site_scope'] ?? '') === 'mine') {
            $filters['site_ids'] = $user->sites()->where('sites.organization_id', $user->organization_id)->pluck('sites.id')->all();
            // Une vue « mes agences » sans affectation ne doit jamais devenir « toutes ».
            abort_if(empty($filters['site_ids']), 422, 'Aucune agence ne vous est affectée pour cette vue.');
        } elseif (! empty($filters['site_ids'])) {
            $allowed = $user->isAdmin()
                ? Site::where('organization_id', $user->organization_id)->pluck('id')->all()
                : $user->sites()->where('sites.organization_id', $user->organization_id)->pluck('sites.id')->all();
            abort_if(count(array_diff($filters['site_ids'], $allowed)) > 0, 403, 'Cette vue contient une agence à laquelle vous n’avez plus accès.');
        }
        // Les critères de la vue remplacent ceux de l'URL ; la liste reste l'autorité métier.
        $request->merge([...array_fill_keys($keys, null), ...$filters]);

        return $this->payload($view, $user);
    }

    public function validate(Request $request, string $scope, ?SavedFilter $savedFilter = null): array
    {
        $user = $request->user();
        $this->owned($user, $scope);
        $request->merge(['name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name')]);
        $nameRule = Rule::unique('saved_filters', 'name')
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('scope', $scope);
        if ($savedFilter) {
            $nameRule->ignore($savedFilter->id);
        }

        // Renaming never replaces the saved criteria with the current page's criteria.
        $rules = [
            'name' => ['required', 'string', 'max:80', $nameRule],
            'visibility' => ['required', Rule::in(['personal', 'shared'])],
            'is_default' => ['sometimes', 'boolean'],
        ];
        if ($request->input('visibility') === 'shared') {
            abort_unless($this->canShare($user, $scope), 403);
        }
        if (! $savedFilter) {
            $config = $this->config($user, $scope);
            $rules['filters'] = ['required', 'array:'.implode(',', $this->filterKeys($user, $scope)), 'min:1'];
            foreach ($config['criteria'] as $key => $keyRules) {
                $rules["filters.{$key}"] = ['sometimes', ...$keyRules];
            }
            if ($config['sites']) {
                $rules['filters.site_scope'] = ['sometimes', Rule::in(['mine'])];
                $rules['filters.site_ids'] = ['sometimes', 'array', 'max:100'];
                $rules['filters.site_ids.*'] = ['required', 'ulid', 'distinct', Rule::exists('sites', 'id')->where('organization_id', $user->organization_id)];
                if (! $user->isAdmin()) {
                    $rules['filters.site_ids.*'][] = Rule::in($user->sites()->pluck('sites.id')->all());
                }
            }
        }

        return $request->validate($rules, [
            'name.unique' => 'Un filtre porte déjà ce nom.',
            'filters.required' => 'Choisissez au moins un critère à enregistrer.',
        ]);
    }
}
