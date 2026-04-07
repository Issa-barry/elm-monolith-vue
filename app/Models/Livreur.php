<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Livreur extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'nom',
        'prenom',
        'telephone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Accessor ──────────────────────────────────────────────────────────────

    public function getNomCompletAttribute(): string
    {
        return trim("{$this->prenom} {$this->nom}");
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function equipes(): BelongsToMany
    {
        return $this->belongsToMany(EquipeLivraison::class, 'equipe_livreurs', 'livreur_id', 'equipe_id')
            ->withPivot(['role', 'taux_commission', 'ordre'])
            ->withTimestamps();
    }
}
