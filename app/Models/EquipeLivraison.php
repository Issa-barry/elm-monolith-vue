<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EquipeLivraison extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'equipes_livraison';

    protected $fillable = [
        'organization_id',
        'nom',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Membres de l'équipe avec leur taux (via pivot equipe_livreurs).
     */
    public function livreurs(): BelongsToMany
    {
        return $this->belongsToMany(Livreur::class, 'equipe_livreurs', 'equipe_id', 'livreur_id')
            ->withPivot(['role', 'taux_commission', 'ordre'])
            ->withTimestamps()
            ->orderByPivot('ordre');
    }

    /**
     * Lignes du pivot directement.
     */
    public function membres(): HasMany
    {
        return $this->hasMany(EquipeLivreur::class, 'equipe_id')->orderBy('ordre');
    }

    public function vehicules(): HasMany
    {
        return $this->hasMany(Vehicule::class, 'equipe_livraison_id');
    }

    // ── Métier ────────────────────────────────────────────────────────────────

    /**
     * Somme des taux de tous les membres (hors propriétaire).
     */
    public function sommeTaux(): float
    {
        return (float) $this->membres()->sum('taux_commission');
    }
}
