<?php

namespace App\Models;

use App\Enums\StatutPeriodeComptable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodeComptable extends Model
{
    use HasUlids;

    protected $table = 'periodes_comptables';

    protected $fillable = [
        'organization_id',
        'exercice_comptable_id',
        'date_debut',
        'date_fin',
        'statut',
        'cloture_at',
        'cloture_by',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'statut' => StatutPeriodeComptable::class,
            'cloture_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(ExerciceComptable::class, 'exercice_comptable_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(PieceComptable::class);
    }

    public function isCloturee(): bool
    {
        return $this->statut === StatutPeriodeComptable::CLOTUREE;
    }
}
