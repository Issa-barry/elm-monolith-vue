<?php

namespace App\Models;

use App\Enums\CategorieTypeRemuneration;
use App\Enums\ModeCalculRemuneration;
use App\Enums\NatureMontantRemuneration;
use App\Enums\PeriodiciteRemuneration;
use App\Enums\StatutTypeRemuneration;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Type de prime ou d'avantage (référentiel RH par organisation, docs/primes-avantages.md). Toutes
 * les écritures passent par App\Services\Rh\TypeRemunerationService (validation, statuts, journal).
 */
class TypeRemuneration extends Model
{
    use HasUlids;

    protected $table = 'types_remuneration';

    protected $fillable = [
        'organization_id',
        'code',
        'libelle',
        'description',
        'categorie',
        'mode_calcul',
        'periodicite',
        'nature_montant',
        'proratisable',
        'cumul_autorise',
        'montant_defaut',
        'taux_defaut',
        'date_debut_application',
        'date_fin_application',
        'statut',
        'cree_par',
        'modifie_par',
        'valide_par',
        'valide_le',
    ];

    protected function casts(): array
    {
        return [
            'categorie' => CategorieTypeRemuneration::class,
            'mode_calcul' => ModeCalculRemuneration::class,
            'periodicite' => PeriodiciteRemuneration::class,
            'nature_montant' => NatureMontantRemuneration::class,
            'statut' => StatutTypeRemuneration::class,
            'proratisable' => 'boolean',
            'cumul_autorise' => 'boolean',
            'montant_defaut' => 'decimal:2',
            'taux_defaut' => 'decimal:2',
            'date_debut_application' => 'date',
            'date_fin_application' => 'date',
            'valide_le' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function modificateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modifie_par');
    }

    public function valideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    /**
     * Types qu'on peut attribuer à un salarié à cette date : actifs ET dans leur fenêtre
     * d'application. Un type à valider, fermé ou inactif n'est jamais attribuable.
     */
    public function scopeAttribuables(Builder $query, string $organizationId, CarbonInterface $date): Builder
    {
        $jour = $date->toDateString();

        return $query->where('organization_id', $organizationId)
            ->where('statut', StatutTypeRemuneration::ACTIF->value)
            ->where(fn (Builder $q) => $q->whereNull('date_debut_application')->orWhereDate('date_debut_application', '<=', $jour))
            ->where(fn (Builder $q) => $q->whereNull('date_fin_application')->orWhereDate('date_fin_application', '>=', $jour));
    }

    public function estAttribuable(CarbonInterface $date): bool
    {
        if ($this->statut !== StatutTypeRemuneration::ACTIF) {
            return false;
        }

        $jour = $date->copy()->startOfDay();

        return ($this->date_debut_application === null || $this->date_debut_application->lte($jour))
            && ($this->date_fin_application === null || $this->date_fin_application->gte($jour));
    }

    /**
     * Le type a-t-il servi (attribution à un salarié, élément de paie) ? Aucune table ne le
     * référence encore : les attributions arrivent au lot 3, qui complétera ce contrôle. Un type
     * utilisé ne se supprime jamais et ses caractéristiques de calcul sont figées.
     */
    public function estUtilise(): bool
    {
        return false;
    }
}
