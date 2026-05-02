<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EquipeLivraison extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'equipes_livraison';

    protected $fillable = [
        'organization_id',
        'vehicule_id',
        'proprietaire_id',
        'nom',
        'is_active',
        'commission_unitaire_par_pack',
        'montant_par_pack_proprietaire',
        'taux_commission_proprietaire',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'commission_unitaire_par_pack' => 'decimal:2',
            'montant_par_pack_proprietaire' => 'decimal:2',
            'taux_commission_proprietaire' => 'decimal:2',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(Proprietaire::class);
    }

    /**
     * Membres de l'équipe (via pivot equipe_livreurs).
     */
    public function livreurs(): BelongsToMany
    {
        return $this->belongsToMany(Livreur::class, 'equipe_livreurs', 'equipe_id', 'livreur_id')
            ->withPivot(['role', 'montant_par_pack', 'taux_commission', 'ordre'])
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

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class, 'vehicule_id');
    }

    // ── Métier ────────────────────────────────────────────────────────────────

    /**
     * Somme des taux dérivés de tous les membres (hors propriétaire).
     */
    public function sommeTaux(): float
    {
        return (float) $this->membres()->sum('taux_commission');
    }
}
