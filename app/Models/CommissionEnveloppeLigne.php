<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Traçabilité : explique comment le montant d'une CommissionEnveloppe a été
 * obtenu, ligne de commande par ligne de commande, règle par règle.
 */
class CommissionEnveloppeLigne extends Model
{
    use HasUlids;

    protected $table = 'commission_enveloppe_lignes';

    protected $fillable = [
        'enveloppe_id',
        'source_ligne_type',
        'source_ligne_id',
        'variante_id',
        'categorie_id_snapshot',
        'commission_regle_id',
        'quantite',
        'unite_calcul_snapshot',
        'montant_ligne',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:2',
            'montant_ligne' => 'decimal:2',
        ];
    }

    public function enveloppe(): BelongsTo
    {
        return $this->belongsTo(CommissionEnveloppe::class, 'enveloppe_id');
    }

    public function sourceLigne(): MorphTo
    {
        return $this->morphTo();
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProduitVariante::class, 'variante_id');
    }

    public function categorieSnapshot(): BelongsTo
    {
        return $this->belongsTo(Categorie::class, 'categorie_id_snapshot');
    }

    public function regle(): BelongsTo
    {
        return $this->belongsTo(CommissionRegle::class, 'commission_regle_id');
    }
}
