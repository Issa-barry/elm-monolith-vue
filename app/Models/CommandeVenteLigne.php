<?php

namespace App\Models;

use App\Enums\TypeEcartLogistique;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommandeVenteLigne extends Model
{
    use HasFactory, HasUlids;

    protected $table = 'commande_vente_lignes';

    protected $fillable = [
        'commande_vente_id',
        'produit_id',
        'quantite_demandee',
        'quantite_chargee',
        'quantite_livree',
        'type_ecart',
        'commentaire_ecart',
        'prix_usine_snapshot',
        'prix_vente_snapshot',
        'total_ligne',
    ];

    protected function casts(): array
    {
        return [
            'quantite_demandee' => 'integer',
            'quantite_chargee' => 'integer',
            'quantite_livree' => 'integer',
            'type_ecart' => TypeEcartLogistique::class,
            'prix_usine_snapshot' => 'decimal:2',
            'prix_vente_snapshot' => 'decimal:2',
            'total_ligne' => 'decimal:2',
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

    // ── Accessors écart ───────────────────────────────────────────────────────

    public function getEcartChargementAttribute(): ?int
    {
        if ($this->quantite_chargee === null) {
            return null;
        }

        return $this->quantite_chargee - $this->quantite_demandee;
    }

    public function getEcartLivraisonAttribute(): ?int
    {
        if ($this->quantite_livree === null || $this->quantite_chargee === null) {
            return null;
        }

        return $this->quantite_livree - $this->quantite_chargee;
    }

    public function getAEcartAttribute(): bool
    {
        return $this->ecart_chargement !== null && $this->ecart_chargement !== 0;
    }
}
