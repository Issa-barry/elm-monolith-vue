<?php

namespace App\Models;

use App\Enums\StatutCommission;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionLogistiquePart extends Model
{
    use HasUlids;

    protected $table = 'commission_logistique_parts';

    protected $fillable = [
        'commission_logistique_id',
        'type_beneficiaire',
        'livreur_id',
        'proprietaire_id',
        'beneficiaire_nom',
        'taux_commission',
        'montant_brut',
        'frais_supplementaires',
        'type_frais',
        'commentaire_frais',
        'montant_net',
        'montant_verse',
        'statut',
        'earned_at',
        'periode',
    ];

    protected $appends = ['montant_restant', 'statut_label', 'statut_dot_class'];

    protected function casts(): array
    {
        return [
            'taux_commission'       => 'decimal:2',
            'montant_brut'          => 'decimal:2',
            'frais_supplementaires' => 'decimal:2',
            'montant_net'           => 'decimal:2',
            'montant_verse'         => 'decimal:2',
            'statut'                => StatutCommission::class,
            'earned_at'             => 'date',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function commission(): BelongsTo
    {
        return $this->belongsTo(CommissionLogistique::class, 'commission_logistique_id');
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class);
    }

    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(Proprietaire::class);
    }

    public function versements(): HasMany
    {
        return $this->hasMany(VersementCommissionLogistique::class, 'commission_logistique_part_id');
    }

    public function paymentItems(): HasMany
    {
        return $this->hasMany(CommissionPaymentItem::class, 'part_id');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getMontantRestantAttribute(): float
    {
        return max(0.0, (float) $this->montant_net - (float) $this->montant_verse);
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

    // ── État ──────────────────────────────────────────────────────────────────

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

    public function isPayable(): bool
    {
        return $this->statut instanceof StatutCommission && $this->statut->isPayable();
    }

    // ── Métier ────────────────────────────────────────────────────────────────

    /**
     * Recalcule montant_verse + statut à partir du total alloué
     * (paiements groupés + versements legacy).
     * Propage ensuite au header de commission.
     */
    public function recalculStatut(): bool
    {
        $versePayments = (float) $this->paymentItems()->sum('amount_allocated');
        $verseLegacy   = (float) $this->versements()->sum('montant');
        $verse = $versePayments + $verseLegacy;
        $net   = (float) $this->montant_net;

        $this->montant_verse = $verse;

        $this->statut = match (true) {
            $net > 0 && $verse >= $net => StatutCommission::PAYE,
            $verse > 0                 => StatutCommission::PARTIEL,
            default                    => StatutCommission::IMPAYE,
        };

        $saved = $this->saveQuietly();
        $this->commission->recalculStatutGlobal();

        return $saved;
    }

    /**
     * Applique des frais et recalcule montant_net.
     */
    public function appliquerFrais(float $frais, ?string $typeFrais = null, ?string $commentaireFrais = null): bool
    {
        $this->frais_supplementaires = max(0.0, $frais);
        $this->montant_net = max(0.0, round((float) $this->montant_brut - $this->frais_supplementaires, 2));
        $this->type_frais = $frais > 0 ? $typeFrais : null;
        $this->commentaire_frais = ($frais > 0 && $typeFrais === 'autre') ? $commentaireFrais : null;

        return $this->save();
    }
}
