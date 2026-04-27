<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommandeAchatLigne extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'commande_achat_lignes';

    protected $fillable = [
        'commande_achat_id',
        'produit_id',
        'qte',
        'qte_recue',
        'prix_achat_snapshot',
        'total_ligne',
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

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
