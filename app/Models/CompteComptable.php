<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompteComptable extends Model
{
    use HasUlids;

    protected $table = 'comptes_comptables';

    protected $fillable = [
        'organization_id',
        'numero',
        'libelle',
        'parent_id',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function enfants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Classe SYSCOHADA (1 à 9) déduite du premier chiffre du numéro — jamais
     * stockée séparément pour éviter une désynchronisation avec `numero`.
     */
    public function getClasseAttribute(): ?int
    {
        return isset($this->numero[0]) && ctype_digit($this->numero[0])
            ? (int) $this->numero[0]
            : null;
    }
}
