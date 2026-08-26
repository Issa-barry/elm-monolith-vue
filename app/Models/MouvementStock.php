<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MouvementStock extends Model
{
    use HasUlids;

    protected $table = 'mouvements_stock';

    protected $fillable = [
        'organization_id',
        'site_id',
        'produit_variante_id',
        'type',
        'quantite',
        'stock_avant',
        'stock_apres',
        'source_type',
        'source_id',
        'notes',
        'created_by',
        'annule_par_id',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'stock_avant' => 'integer',
            'stock_apres' => 'integer',
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

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProduitVariante::class, 'produit_variante_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Le contre-mouvement qui a annulé celui-ci — null tant qu'il est actif. */
    public function annulePar(): BelongsTo
    {
        return $this->belongsTo(self::class, 'annule_par_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isEntree(): bool
    {
        return $this->type === 'entree';
    }

    public function isSortie(): bool
    {
        return $this->type === 'sortie';
    }

    public function isAnnule(): bool
    {
        return $this->annule_par_id !== null;
    }
}
