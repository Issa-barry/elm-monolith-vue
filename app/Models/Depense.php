<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Depense extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'site_id',
        'user_id',
        'depense_type_id',
        'vehicule_id',
        'montant',
        'date_depense',
        'commentaire',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'montant'      => 'decimal:2',
            'date_depense' => 'date',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function depenseType(): BelongsTo
    {
        return $this->belongsTo(DepenseType::class);
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForOrg($query, string $orgId)
    {
        return $query->where('organization_id', $orgId);
    }
}
