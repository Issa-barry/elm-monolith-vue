<?php

namespace App\Models;

use App\Enums\StatutExerciceComptable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExerciceComptable extends Model
{
    use HasUlids;

    protected $table = 'exercices_comptables';

    protected $fillable = [
        'organization_id',
        'libelle',
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
            'statut' => StatutExerciceComptable::class,
            'cloture_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function periodes(): HasMany
    {
        return $this->hasMany(PeriodeComptable::class);
    }

    public function isCloture(): bool
    {
        return $this->statut === StatutExerciceComptable::CLOTURE;
    }
}
