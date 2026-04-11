<?php

namespace App\Models;

use App\Enums\ModePaiement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaiementCommissionVente extends Model
{
    protected $table = 'paiements_commissions_ventes';

    protected $fillable = [
        'organization_id',
        'type_beneficiaire',
        'livreur_id',
        'proprietaire_id',
        'beneficiaire_nom',
        'montant',
        'mode_paiement',
        'note',
        'paid_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'paid_at' => 'date',
            'mode_paiement' => ModePaiement::class,
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function items(): HasMany
    {
        return $this->hasMany(PaiementCommissionVenteItem::class, 'paiement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class);
    }

    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(Proprietaire::class);
    }
}
