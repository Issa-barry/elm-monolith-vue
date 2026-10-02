<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Changement de barème en préparation (lot 2, ADR 0006) : `lignes` est la configuration complète
 * de Paramètres → Commissions pour ce processus, jamais appliquée avant publication — le barème
 * réellement en vigueur reste celui des CommissionRegle actives. Au plus un brouillon EN COURS
 * par (organisation, processus), cf. ReconfigurationPartagesService.
 */
class CommissionBaremeBrouillon extends Model
{
    use HasUlids;

    public const STATUT_EN_COURS = 'en_cours';

    public const STATUT_PUBLIE = 'publie';

    public const STATUT_ABANDONNE = 'abandonne';

    protected $table = 'commission_bareme_brouillons';

    protected $fillable = [
        'organization_id',
        'processus_id',
        'lignes',
        'regles_signature',
        'statut',
        'created_by',
        'updated_by',
        'publie_par',
        'publie_le',
        'abandonne_le',
    ];

    protected function casts(): array
    {
        return [
            'lignes' => 'array',
            'publie_le' => 'datetime',
            'abandonne_le' => 'datetime',
        ];
    }

    public function estEnCours(): bool
    {
        return $this->statut === self::STATUT_EN_COURS;
    }

    public static function enCoursPour(string $organizationId, string $processusId): ?self
    {
        return self::where('organization_id', $organizationId)
            ->where('processus_id', $processusId)
            ->where('statut', self::STATUT_EN_COURS)
            ->latest('created_at')
            ->first();
    }

    public function processus(): BelongsTo
    {
        return $this->belongsTo(CommissionProcessus::class, 'processus_id');
    }

    public function partages(): HasMany
    {
        return $this->hasMany(CommissionBaremeBrouillonPartage::class, 'brouillon_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
