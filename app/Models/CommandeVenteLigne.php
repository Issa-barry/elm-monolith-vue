<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommandeVenteLigne extends Model
{
    use HasFactory;

    protected $table = 'commande_vente_lignes';

    protected $fillable = [
        'commande_vente_id',
        'produit_id',
        'qte',
        'prix_usine_snapshot',
        'prix_vente_snapshot',
        'total_ligne',
    ];

    protected function casts(): array
    {
        return [
            'qte'                 => 'integer',
            'prix_usine_snapshot' => 'decimal:2',
            'prix_vente_snapshot' => 'decimal:2',
            'total_ligne'         => 'decimal:2',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeVente::class, 'commande_vente_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
