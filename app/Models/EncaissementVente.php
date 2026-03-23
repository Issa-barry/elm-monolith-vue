<?php

namespace App\Models;

use App\Enums\ModePaiement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class EncaissementVente extends Model
{
    use HasFactory;

    protected $table = 'encaissements_ventes';

    protected $fillable = [
        'facture_vente_id',
        'montant',
        'date_encaissement',
        'mode_paiement',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'montant'           => 'decimal:2',
            'date_encaissement' => 'date:Y-m-d',
            'mode_paiement'     => ModePaiement::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EncaissementVente $e) {
            if (Auth::check()) {
                $e->created_by = Auth::id();
            }
        });

        static::created(function (EncaissementVente $e) {
            $facture = $e->facture;
            if ($facture) {
                $facture->recalculStatut();
                $facture->commande->cloturerSiComplete();
            }
        });

        static::deleted(function (EncaissementVente $e) {
            $facture = $e->facture;
            if ($facture) {
                $facture->recalculStatut();
                $facture->commande->cloturerSiComplete();
            }
        });
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function facture(): BelongsTo
    {
        return $this->belongsTo(FactureVente::class, 'facture_vente_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
