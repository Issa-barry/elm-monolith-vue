<?php

namespace App\Models;

use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaiementPeriode extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'reference',
        'type',
        'site_id',
        'date_debut',
        'date_fin',
        'statut',
        'observations',
        'created_by',
        'validated_by',
        'validated_at',
        'calcul_hash',
        'calculated_at',
    ];

    protected $appends = ['nb_fiches', 'total_net', 'total_paye', 'statut_label'];

    protected function casts(): array
    {
        return [
            'type' => TypePeriodePaiement::class,
            'statut' => StatutPeriodePaiement::class,
            'date_debut' => 'date',
            'date_fin' => 'date',
            'validated_at' => 'datetime',
            'calculated_at' => 'datetime',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function fiches(): HasMany
    {
        return $this->hasMany(PaiementFiche::class, 'periode_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    // Valeurs préchargées par avecTotauxFiches() quand elles existent : une liste ne refait pas
    // trois requêtes par période.
    public function getNbFichesAttribute(): int
    {
        return array_key_exists('fiches_count', $this->attributes)
            ? (int) $this->attributes['fiches_count']
            : $this->fiches()->count();
    }

    public function getTotalNetAttribute(): float
    {
        return array_key_exists('fiches_sum_montant_net', $this->attributes)
            ? (float) $this->attributes['fiches_sum_montant_net']
            : (float) $this->fiches()->sum('montant_net');
    }

    public function getTotalPayeAttribute(): float
    {
        return array_key_exists('fiches_sum_montant_paye', $this->attributes)
            ? (float) $this->attributes['fiches_sum_montant_paye']
            : (float) $this->fiches()->sum('montant_paye');
    }

    public function scopeAvecTotauxFiches(Builder $query): Builder
    {
        return $query->withCount('fiches')
            ->withSum('fiches', 'montant_net')
            ->withSum('fiches', 'montant_paye');
    }

    public function getStatutLabelAttribute(): string
    {
        return $this->statut instanceof StatutPeriodePaiement ? $this->statut->label() : '';
    }

    // ── État ──────────────────────────────────────────────────────────────────

    public function isBrouillon(): bool
    {
        return $this->statut === StatutPeriodePaiement::BROUILLON;
    }

    public function isCalculee(): bool
    {
        return $this->statut === StatutPeriodePaiement::CALCULEE;
    }

    public function isValidee(): bool
    {
        return $this->statut === StatutPeriodePaiement::VALIDEE;
    }

    public function isCloturee(): bool
    {
        return $this->statut === StatutPeriodePaiement::CLOTUREE;
    }

    public function peutEtreCalculee(): bool
    {
        return $this->isBrouillon() || $this->isCalculee();
    }

    public function peutEtreValidee(): bool
    {
        return $this->isCalculee();
    }

    public function peutEtreCloturee(): bool
    {
        return $this->isValidee();
    }

    public function peutEtrePayee(): bool
    {
        return $this->isValidee();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForOrg(Builder $query, string $orgId): Builder
    {
        return $query->where('organization_id', $orgId);
    }
}
