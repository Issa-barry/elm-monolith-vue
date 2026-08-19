<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Allocation d'un paiement de fiche (PaiementFichePaiement — partagé
 * Legacy/V2) sur une CommissionEnveloppePart précise. N'existe QUE pour les
 * paiements V2 (cf. décision AMOA — une seule chaîne de paiement pour V2,
 * jamais un second circuit) : un PaiementFichePaiement legacy n'a jamais
 * d'items ici, seuls ses PaiementFicheLigne(source=CommissionPart) existent.
 */
class CommissionEnveloppePaiementItem extends Model
{
    use HasUlids;

    protected $table = 'commission_enveloppe_paiement_items';

    protected $fillable = [
        'paiement_id',
        'commission_enveloppe_part_id',
        'amount_allocated',
    ];

    protected function casts(): array
    {
        return [
            'amount_allocated' => 'decimal:2',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function paiement(): BelongsTo
    {
        return $this->belongsTo(PaiementFichePaiement::class, 'paiement_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(CommissionEnveloppePart::class, 'commission_enveloppe_part_id');
    }
}
