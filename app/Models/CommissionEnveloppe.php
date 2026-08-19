<?php

namespace App\Models;

use App\Enums\StatutCommission;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Le montant total gagné par une cible bénéficiaire précise sur une opération
 * précise — jamais un simple total interne au calcul. Une seule enveloppe par
 * (cible, opération), même si plusieurs règles/catégories y contribuent
 * (décision AMOA #6) : le détail par règle vit dans les lignes, pas ici.
 */
class CommissionEnveloppe extends Model
{
    use HasUlids;

    protected $table = 'commission_enveloppes';

    protected $fillable = [
        'organization_id',
        'source_type',
        'source_id',
        'processus_id',
        'cible_type',
        'cible_id',
        'montant_total',
        'earned_at',
        'periode_code',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'montant_total' => 'decimal:2',
            'earned_at' => 'date',
            'statut' => StatutCommission::class,
        ];
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

    public function lignes(): HasMany
    {
        return $this->hasMany(CommissionEnveloppeLigne::class, 'enveloppe_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(CommissionEnveloppePart::class, 'enveloppe_id');
    }
}
