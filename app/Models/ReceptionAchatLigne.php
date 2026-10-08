<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceptionAchatLigne extends Model
{
    use HasUlids;

    protected $table = 'reception_achat_lignes';

    protected $fillable = [
        'reception_achat_id',
        'commande_achat_ligne_id',
        'variante_id',
        'qte_recue',
        'cout_unitaire',
        'mouvement_stock_id',
    ];

    protected function casts(): array
    {
        return [
            'qte_recue' => 'integer',
            'cout_unitaire' => 'decimal:2',
        ];
    }

    public function reception(): BelongsTo
    {
        return $this->belongsTo(ReceptionAchat::class, 'reception_achat_id');
    }

    public function commandeLigne(): BelongsTo
    {
        return $this->belongsTo(CommandeAchatLigne::class, 'commande_achat_ligne_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProduitVariante::class, 'variante_id');
    }

    public function factureLignes(): HasMany
    {
        return $this->hasMany(FactureFournisseurLigne::class);
    }
}
