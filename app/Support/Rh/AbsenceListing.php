<?php

namespace App\Support\Rh;

use App\Enums\StatutAbsence;
use App\Models\Absence;
use App\Models\User;
use App\Services\SiteScopeService;
use App\Support\Periodes\Periode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Requête de la liste des absences (docs/rh-absences.md) : périmètre d'agences du salarié
 * (consultation, ADR 0025) puis filtres. Une seule source pour la liste et les options du filtre
 * Auteur, afin qu'aucun choix proposé ne sorte du périmètre.
 */
final class AbsenceListing
{
    /** Valeurs du filtre Statut ; vide = toutes les absences enregistrées (annulées exclues). */
    public const ETATS = ['en_cours', 'a_venir', 'terminee', 'annulee'];

    /** @return Builder<Absence> */
    public function perimetre(User $user): Builder
    {
        $query = Absence::where('organization_id', $user->organization_id);

        if (! $user->voitToutesLesAgences()) {
            $query->whereHas('employe', fn ($e) => app(SiteScopeService::class)->applyToQuery($e, $user));
        }

        return $query;
    }

    /**
     * @param  array{recherche?: ?string, motif_id?: ?string, auteur_id?: ?string, statut?: ?string}  $filtres
     * @param  list<string>  $siteIds  agences choisies (vide = tout le périmètre)
     * @return Builder<Absence>
     */
    public function query(User $user, array $filtres, array $siteIds, Periode $periode): Builder
    {
        $query = $this->perimetre($user);

        if ($siteIds !== []) {
            $query->whereHas('employe', fn ($e) => $e->whereIn('site_id', $siteIds));
        }

        $recherche = trim((string) ($filtres['recherche'] ?? ''));
        if ($recherche !== '') {
            $query->whereHas('employe', function ($e) use ($recherche) {
                $e->where(function ($q) use ($recherche) {
                    $q->where('matricule', $recherche)
                        ->orWhereHas('personne', fn ($p) => $p
                            ->where('nom', 'like', "%{$recherche}%")
                            ->orWhere('prenom', 'like', "%{$recherche}%"));
                });
            });
        }

        if (! empty($filtres['motif_id'])) {
            $query->where('motif_absence_id', $filtres['motif_id']);
        }

        if (! empty($filtres['auteur_id'])) {
            $query->where('cree_par', $filtres['auteur_id']);
        }

        $this->appliquerEtat($query, (string) ($filtres['statut'] ?? ''));

        // Période : absences qui touchent la période choisie (pas seulement celles qui y commencent).
        if ($periode->estBornee()) {
            $query->chevauchant($periode->debut, $periode->fin);
        }

        return $query->orderByDesc('date_debut')->orderByDesc('created_at');
    }

    /**
     * Auteurs des saisies du périmètre, pour le filtre Auteur.
     *
     * @return list<array{value: string, label: string}>
     */
    public function auteurs(User $user): array
    {
        // Le nom vient de la Personne (User::getNameAttribute) : tri après chargement.
        return User::whereIn('id', $this->perimetre($user)->whereNotNull('cree_par')->select('cree_par'))
            ->with('personne:id,nom,prenom')
            ->get(['id', 'personne_id'])
            ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])
            ->sortBy(fn (array $o) => mb_strtolower($o['label']))
            ->values()
            ->all();
    }

    /** @param  Builder<Absence>  $query */
    private function appliquerEtat(Builder $query, string $etat): void
    {
        if ($etat === 'annulee') {
            $query->where('statut', StatutAbsence::ANNULEE->value);

            return;
        }

        $query->enregistrees();
        // Bornes semi-ouvertes (cf. Absence::scopeChevauchant) : « aujourd'hui inclus » = « avant demain ».
        $aujourdhui = now()->toDateString();
        $demain = now()->startOfDay()->addDay()->toDateString();

        match ($etat) {
            'en_cours' => $query->where('date_debut', '<', $demain)->where('date_fin', '>=', $aujourdhui),
            'a_venir' => $query->where('date_debut', '>=', $demain),
            'terminee' => $query->where('date_fin', '<', $aujourdhui),
            default => null,
        };
    }
}
