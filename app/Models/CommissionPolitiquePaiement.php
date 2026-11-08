<?php

namespace App\Models;

use App\Services\Commission\AgencePayeuseCommissionService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Politique de paiement des commissions d'une organisation (ADR 0061) : agence centralisée (vide =
 * trésorerie principale) et, par type de bénéficiaire de fiche, `['mode' => ..., 'site_id' => ...]`.
 * Lue uniquement à travers AgencePayeuseCommissionService.
 */
class CommissionPolitiquePaiement extends Model
{
    use HasUlids;

    protected $table = 'commission_politiques_paiement';

    protected $fillable = [
        'organization_id',
        'agence_centralisee_id',
        'regles',
        'updated_by',
    ];

    protected function casts(): array
    {
        return ['regles' => 'array'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => app(AgencePayeuseCommissionService::class)->oublier());
        static::deleted(fn () => app(AgencePayeuseCommissionService::class)->oublier());
    }

    public function agenceCentralisee(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'agence_centralisee_id');
    }
}
