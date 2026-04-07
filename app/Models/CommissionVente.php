<?php

namespace App\Models;

use App\Enums\StatutCommission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommissionVente extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'commissions_ventes';

    protected $fillable = [
        'organization_id',
        'commande_vente_id',
        'vehicule_id',
        'montant_commande',
        'montant_commission_totale',
        'montant_verse',
        'statut',
    ];

    protected $appends = ['montant_restant', 'statut_label'];

    protected function casts(): array
    {
        return [
            'montant_commande' => 'decimal:2',
            'montant_commission_totale' => 'decimal:2',
            'montant_verse' => 'decimal:2',
            'statut' => StatutCommission::class,
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeVente::class, 'commande_vente_id');
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(CommissionPart::class, 'commission_vente_id');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getMontantRestantAttribute(): float
    {
        if ($this->relationLoaded('parts')) {
            return (float) $this->parts->sum('montant_restant');
        }

        return (float) $this->parts()->get()->sum('montant_restant');
    }

    public function getStatutLabelAttribute(): string
    {
        return $this->statut instanceof StatutCommission ? $this->statut->label() : '';
    }

    // ── Métier ────────────────────────────────────────────────────────────────

    public function isVersee(): bool
    {
        return $this->statut === StatutCommission::VERSEE;
    }

    public function isAnnulee(): bool
    {
        return $this->statut === StatutCommission::ANNULEE;
    }

    /**
     * Recalcule montant_verse + statut global depuis les parts.
     */
    public function recalculStatutGlobal(): bool
    {
        if ($this->isAnnulee()) {
            return false;
        }

        $parts = $this->parts()->get();

        $totalVerse = $parts->sum('montant_verse');
        $totalNet = $parts->sum('montant_net');

        $this->montant_verse = $totalVerse;

        if ($totalVerse <= 0) {
            $this->statut = StatutCommission::EN_ATTENTE;
        } elseif ($totalNet > 0 && $totalVerse >= $totalNet) {
            $this->statut = StatutCommission::VERSEE;
        } else {
            $this->statut = StatutCommission::PARTIELLE;
        }

        return $this->saveQuietly();
    }
}
