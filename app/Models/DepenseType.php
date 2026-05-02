<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DepenseType extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'depense_types';

    protected $fillable = [
        'organization_id',
        'code',
        'libelle',
        'description',
        'requires_vehicle',
        'requires_comment',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'requires_vehicle' => 'boolean',
            'requires_comment' => 'boolean',
            'is_active'        => 'boolean',
            'sort_order'       => 'integer',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('libelle');
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
