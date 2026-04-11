<?php

namespace App\Models;

use App\Enums\TypeEcartLogistique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransfertLigne extends Model
{
    protected $table = 'transfert_lignes';

    protected $fillable = [
        'transfert_logistique_id',
        'produit_id',
        'quantite_demandee',
        'quantite_chargee',
        'quantite_recue',
        'ecart_type',
        'ecart_motif',
        'notes',
    ];

    protected $appends = ['ecart', 'ecart_label', 'ecart_dot_class'];

    protected function casts(): array
    {
        return [
            'quantite_demandee' => 'integer',
            'quantite_chargee'  => 'integer',
            'quantite_recue'    => 'integer',
            'ecart_type'        => TypeEcartLogistique::class,
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function transfert(): BelongsTo
    {
        return $this->belongsTo(TransfertLogistique::class, 'transfert_logistique_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    /** Écart numérique : reçu − chargé (null si données incomplètes) */
    public function getEcartAttribute(): ?int
    {
        if ($this->quantite_chargee === null || $this->quantite_recue === null) {
            return null;
        }

        return $this->quantite_recue - $this->quantite_chargee;
    }

    public function getEcartLabelAttribute(): string
    {
        return $this->ecart_type instanceof TypeEcartLogistique
            ? $this->ecart_type->label()
            : '';
    }

    public function getEcartDotClassAttribute(): string
    {
        return $this->ecart_type instanceof TypeEcartLogistique
            ? $this->ecart_type->dotClass()
            : 'bg-zinc-400 dark:bg-zinc-500';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isConforme(): bool
    {
        return $this->ecart_type === TypeEcartLogistique::CONFORME;
    }

    public function hasEcart(): bool
    {
        return $this->ecart !== null && $this->ecart !== 0;
    }

    /** Vérifie que la réception est saisie et l'écart qualifié */
    public function estReceptionComplete(): bool
    {
        return $this->quantite_recue !== null && $this->ecart_type !== null;
    }
}
