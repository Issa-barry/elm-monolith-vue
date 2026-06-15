<?php

namespace App\Models;

use App\Enums\CategorieDepense;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DepenseType extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'depense_types';

    protected $fillable = [
        'organization_id',
        'code',
        'libelle',
        'description',
        'categorie',
        'commentaire_obligatoire',
        'justificatif_obligatoire',
        'type_paie',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'categorie' => CategorieDepense::class,
            'commentaire_obligatoire' => 'boolean',
            'justificatif_obligatoire' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('libelle');
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function depenses(): HasMany
    {
        return $this->hasMany(Depense::class);
    }
}
