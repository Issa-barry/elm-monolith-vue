<?php

namespace App\Models;

use App\Enums\StatutCommission;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommissionVente extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

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

    protected $appends = ['montant_restant', 'statut_label', 'statut_dot_class'];

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

    public function getStatutDotClassAttribute(): string
    {
        return $this->statut instanceof StatutCommission
            ? $this->statut->dotClass()
            : 'bg-zinc-400 dark:bg-zinc-500';
    }

    // ── Métier ────────────────────────────────────────────────────────────────

    public function isPaye(): bool
    {
        return $this->statut === StatutCommission::PAYE;
    }

    /** @deprecated use isPaye() */
    public function isVersee(): bool
    {
        return $this->isPaye();
    }

    /**
     * Recalcule montant_verse + statut global depuis les parts.
     */
    public function recalculStatutGlobal(): bool
    {
        if (in_array($this->statut, [StatutCommission::CREEE, StatutCommission::ANNULEE], true)) {
            return false;
        }

        $parts = $this->parts()->get();

        $totalVerse = $parts->sum('montant_verse');
        $totalNet = $parts->sum('montant_net');

        $this->montant_verse = $totalVerse;

        $this->statut = match (true) {
            $totalNet > 0 && (float) $totalVerse >= (float) $totalNet => StatutCommission::PAYE,
            (float) $totalVerse > 0 => StatutCommission::PARTIEL,
            default => StatutCommission::IMPAYE,
        };

        return $this->saveQuietly();
    }
}
