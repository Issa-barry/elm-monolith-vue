<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Motif d'absence (Maladie, Rendez-vous médical…) — référentiel RH isolé par organisation,
 * paramétrable dans Paramètres → RH → Motifs d'absence. Jamais supprimé : seulement désactivé pour
 * de nouvelles saisies, les absences existantes gardent leur motif.
 */
class MotifAbsence extends Model
{
    use HasUlids;

    protected $table = 'motifs_absence';

    protected $fillable = [
        'organization_id',
        'code',
        'libelle',
        'is_active',
        'ordre',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'ordre' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class);
    }

    /** @param  Builder<MotifAbsence>  $query */
    public function scopeOrdonnes(Builder $query): Builder
    {
        return $query->orderBy('ordre')->orderBy('libelle');
    }
}
