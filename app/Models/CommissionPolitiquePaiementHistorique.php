<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un changement de la politique de paiement des commissions (ADR 0061) : avant, après, auteur. */
class CommissionPolitiquePaiementHistorique extends Model
{
    use HasUlids;

    protected $table = 'commission_politique_paiement_historique';

    protected $fillable = [
        'organization_id',
        'user_id',
        'avant',
        'apres',
        'fiches_reaffectees',
    ];

    protected function casts(): array
    {
        return [
            'avant' => 'array',
            'apres' => 'array',
            'fiches_reaffectees' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
