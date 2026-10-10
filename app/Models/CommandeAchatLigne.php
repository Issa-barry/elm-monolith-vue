<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommandeAchatLigne extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'commande_achat_lignes';

    protected $fillable = [
        'commande_achat_id',
        'variante_id',
        'qte',
        'qte_recue',
        'prix_achat_snapshot',
        'total_ligne',
        'libelle_snapshot',
        'reference_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'qte' => 'integer',
            'qte_recue' => 'integer',
            'prix_achat_snapshot' => 'decimal:2',
            'total_ligne' => 'decimal:2',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeAchat::class, 'commande_achat_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProduitVariante::class, 'variante_id');
    }

    public function receptionLignes(): HasMany
    {
        return $this->hasMany(ReceptionAchatLigne::class);
    }

    /** Quantité commandée restant à recevoir. */
    public function reliquat(): int
    {
        return max(0, (int) $this->qte - (int) $this->qte_recue);
    }
}
