<?php

namespace App\Models;

use App\Enums\CommissionGenerationDeclenchePar;
use App\Enums\CommissionGenerationStatut;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Une tentative de génération, réussie ou non — table append-only, jamais
 * mise à jour (cf. migration). Le statut affiché pour une opération
 * ("à régulariser") est une vue calculée à partir de la tentative la plus
 * récente, jamais un champ stocké séparément.
 */
class CommissionGenerationAttempt extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'commission_generation_attempts';

    protected $fillable = [
        'organization_id',
        'source_type',
        'source_id',
        'processus_id',
        'statut',
        'motif_erreur',
        'detail_erreur',
        'declenchee_par',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'statut' => CommissionGenerationStatut::class,
            'detail_erreur' => 'array',
            'declenchee_par' => CommissionGenerationDeclenchePar::class,
        ];
    }

    /**
     * Statut de génération courant d'une opération pour un processus donné —
     * dérivé de la tentative la plus récente, jamais persisté séparément.
     * NULL = aucune tentative n'a encore eu lieu.
     */
    public static function statutCourant(string $sourceType, string $sourceId, string $processusId): ?CommissionGenerationStatut
    {
        $derniere = static::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('processus_id', $processusId)
            ->latest('created_at')
            ->first();

        return $derniere?->statut;
    }

    public static function nombreTentatives(string $sourceType, string $sourceId, string $processusId): int
    {
        return static::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('processus_id', $processusId)
            ->count();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function processus(): BelongsTo
    {
        return $this->belongsTo(CommissionProcessus::class, 'processus_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
