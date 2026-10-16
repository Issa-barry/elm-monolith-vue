<?php

namespace App\Models;

use App\Enums\StatutTransfert;
use App\Enums\TypeEcartLogistique;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransfertLigne extends Model
{
    use HasUlids;

    protected $table = 'transfert_lignes';

    protected $fillable = [
        'transfert_logistique_id',
        'variante_id',
        'quantite_demandee',
        'quantite_chargee',
        'quantite_recue',
        'quantite_retournee',
        'ecart_type',
        'ecart_motif',
        'notes',
    ];

    protected $appends = ['ecart', 'ecart_label', 'ecart_dot_class'];

    protected function casts(): array
    {
        return [
            'quantite_demandee' => 'integer',
            'quantite_chargee' => 'integer',
            'quantite_recue' => 'integer',
            'quantite_retournee' => 'integer',
            'ecart_type' => TypeEcartLogistique::class,
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function transfert(): BelongsTo
    {
        return $this->belongsTo(TransfertLogistique::class, 'transfert_logistique_id');
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProduitVariante::class, 'variante_id');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    /**
     * Écart numérique : reçu + retourné − chargé (null si données incomplètes). La marchandise
     * revenue au site source n'est ni un manquant ni un surplus (ADR 0029) ; sans retour, c'est
     * exactement l'ancien écart reçu − chargé.
     */
    public function getEcartAttribute(): ?int
    {
        if ($this->quantite_chargee === null || $this->quantite_recue === null) {
            return null;
        }

        return $this->quantite_recue + (int) $this->quantite_retournee - $this->quantite_chargee;
    }

    /**
     * Quantité pouvant encore revenir au site source (ADR 0029). En TRANSIT la réception n'est pas
     * effective — une quantité reçue déjà saisie (brouillon de réception, ou reliquat d'une
     * réception invalidée) n'est pas déduite ; en RECEPTION seul ce qui n'a pas été reçu peut
     * revenir. 0 dans tout autre statut.
     */
    public function getQuantiteRetournableAttribute(): int
    {
        $statut = $this->transfert?->statut;
        $chargee = (int) $this->quantite_chargee;
        $retournee = (int) $this->quantite_retournee;

        $retournable = match ($statut) {
            StatutTransfert::TRANSIT => $chargee - $retournee,
            StatutTransfert::RECEPTION => $chargee - (int) $this->quantite_recue - $retournee,
            default => 0,
        };

        return max(0, $retournable);
    }

    /** Quantité maximale réceptionnable une fois les retours déduits — null si aucun retour (surplus permis, ADR 0029 A1). */
    public function getQuantiteRecevableMaxAttribute(): ?int
    {
        if ((int) $this->quantite_retournee === 0) {
            return null;
        }

        return max(0, (int) $this->quantite_chargee - (int) $this->quantite_retournee);
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
