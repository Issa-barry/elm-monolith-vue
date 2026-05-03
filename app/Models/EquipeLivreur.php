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
        'ordre',
    ];

    protected function casts(): array
    {
        return [
            'montant_par_pack' => 'decimal:2',
            'taux_commission' => 'decimal:2',
            'ordre' => 'integer',
        ];
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
