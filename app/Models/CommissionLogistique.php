<?php

namespace App\Models;

use App\Enums\BaseCalculLogistique;
use App\Enums\StatutCommission;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionLogistique extends Model
{
    use HasUlids;

    protected $table = 'commissions_logistiques';

    protected $fillable = [
        'organization_id',
        'transfert_logistique_id',
        'vehicule_id',
        'base_calcul',
        'valeur_base',
        'quantite_reference',
        'montant_total',
        'montant_verse',
        'statut',
    ];

    protected $appends = ['montant_restant', 'statut_label', 'statut_dot_class'];

    protected function casts(): array
    {
        return [
            'base_calcul' => BaseCalculLogistique::class,
            'valeur_base' => 'decimal:2',
            'montant_total' => 'decimal:2',
            'montant_verse' => 'decimal:2',
            'quantite_reference' => 'integer',
            'statut' => StatutCommission::class,
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function transfert(): BelongsTo
    {
        return $this->belongsTo(TransfertLogistique::class, 'transfert_logistique_id');
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(CommissionLogistiquePart::class);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getMontantRestantAttribute(): float
    {
        return max(0.0, (float) $this->montant_total - (float) $this->montant_verse);
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

    // ── Méthodes d'état ───────────────────────────────────────────────────────

    public function isPaye(): bool
    {
        return $this->statut === StatutCommission::PAYE;
    }

    public function isImpaye(): bool
    {
        return $this->statut === StatutCommission::IMPAYE;
    }

    /** @deprecated use isPaye() */
    public function isVersee(): bool
    {
        return $this->isPaye();
    }

    // ── Métier ────────────────────────────────────────────────────────────────

    public function calculerMontantTotal(): float
    {
        return match ($this->base_calcul) {
            BaseCalculLogistique::FORFAIT => (float) $this->valeur_base,
            BaseCalculLogistique::PAR_PACK,
            BaseCalculLogistique::PAR_KM => (float) $this->valeur_base * ($this->quantite_reference ?? 0),
        };
    }

    /**
     * Recalcule montant_verse + statut global depuis les parts.
     */
    public function recalculStatutGlobal(): bool
    {
        $parts = $this->parts()->get();

        $totalVerse = (float) $parts->sum('montant_verse');
        $totalNet = (float) $parts->sum('montant_net');

        $this->montant_verse = $totalVerse;

        $this->statut = match (true) {
            $totalNet > 0 && $totalVerse >= $totalNet => StatutCommission::PAYE,
            $totalVerse > 0 => StatutCommission::PARTIEL,
            default => StatutCommission::IMPAYE,
        };

        return $this->saveQuietly();
    }
}
