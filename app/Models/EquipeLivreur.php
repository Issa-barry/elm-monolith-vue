<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle pivot enrichi pour la table equipe_livreurs.
 */
class EquipeLivreur extends Model
{
    use HasUlids;

    protected $table = 'equipe_livreurs';

    protected $fillable = [
        'equipe_id',
        'livreur_id',
        'role',
        'montant_par_pack',
        'taux_commission',
        'taux_commission_logistique',
        'ordre',
    ];

    protected function casts(): array
    {
        return [
            'montant_par_pack' => 'decimal:2',
            'taux_commission' => 'decimal:2',
            'taux_commission_logistique' => 'decimal:2',
            'ordre' => 'integer',
        ];
    }

    /**
     * Taux applicable à un transfert logistique — barème logistique explicite si configuré,
     * sinon repli sur le barème vente (taux_commission) pour ne rien casser sur les équipes
     * existantes qui n'ont jamais eu besoin de les distinguer.
     */
    public function tauxCommissionLogistiqueEffectif(): float
    {
        return (float) ($this->taux_commission_logistique ?? $this->taux_commission ?? 0);
    }

    public function equipe(): BelongsTo
    {
        return $this->belongsTo(EquipeLivraison::class, 'equipe_id');
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class);
    }
}
