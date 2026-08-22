<?php

namespace App\Models;

use App\Enums\OrigineCommissionPart;
use App\Enums\StatutCommission;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * La part individuelle finale d'un bénéficiaire sur une CommissionEnveloppe —
 * source de vérité unique pour toute commission de vente (méthodes métier :
 * recalculStatut, isPaye, isPayable, estValidee, peutEtreAjustee). Le paiement
 * est alloué via CommissionEnveloppePaiementItem.
 */
class CommissionEnveloppePart extends Model
{
    use HasUlids;

    public const TYPE_LIVREUR = 'livreur';

    public const TYPE_PROPRIETAIRE = 'proprietaire';

    public const TYPE_EMPLOYE = 'employe';

    /** Bénéficiaire = App\Models\Site directement (commission site, jamais une personne). */
    public const TYPE_SITE = 'site';

    protected $table = 'commission_enveloppe_parts';

    protected $fillable = [
        'enveloppe_id',
        'beneficiaire_type',
        'beneficiaire_id',
        'taux_repartition_snapshot',
        'montant_brut',
        'montant_net',
        'montant_verse',
        'montant_actuel',
        'statut',
        'origine',
        'validated_by',
        'validated_at',
    ];

    protected $appends = ['montant_restant'];

    protected function casts(): array
    {
        return [
            'taux_repartition_snapshot' => 'decimal:2',
            'montant_brut' => 'decimal:2',
            'montant_net' => 'decimal:2',
            'montant_verse' => 'decimal:2',
            'montant_actuel' => 'decimal:2',
            'statut' => StatutCommission::class,
            'origine' => OrigineCommissionPart::class,
            'validated_at' => 'datetime',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function enveloppe(): BelongsTo
    {
        return $this->belongsTo(CommissionEnveloppe::class, 'enveloppe_id');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function paiementItems(): HasMany
    {
        return $this->hasMany(CommissionEnveloppePaiementItem::class, 'commission_enveloppe_part_id');
    }

    public function adjustments(): MorphMany
    {
        return $this->morphMany(CommissionPartAdjustment::class, 'commission_part')->latest('created_at');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    /** Montant réellement dû : ajusté par un responsable, sinon montant théorique net. */
    public function getMontantAPayerAttribute(): float
    {
        return (float) ($this->montant_actuel ?? $this->montant_net);
    }

    public function getMontantRestantAttribute(): float
    {
        return max(0.0, $this->montant_a_payer - (float) $this->montant_verse);
    }

    // ── Métier ────────────────────────────────────────────────────────────────

    public function isPaye(): bool
    {
        return $this->statut === StatutCommission::PAYE;
    }

    public function isPayable(): bool
    {
        return $this->statut instanceof StatutCommission && $this->statut->isPayable();
    }

    public function estValidee(): bool
    {
        return $this->validated_at !== null;
    }

    /** Une part déjà entièrement versée ne doit plus pouvoir être ajustée ou validée. */
    public function peutEtreAjustee(): bool
    {
        return ! $this->isPaye();
    }

    /**
     * Recalcule montant_verse + statut à partir des paiements réels.
     * Puis déclenche le recalcul global de l'enveloppe parente.
     */
    public function recalculStatut(): bool
    {
        $verse = (float) $this->paiementItems()->sum('amount_allocated');
        $aPayer = $this->montant_a_payer;

        $this->montant_verse = $verse;

        $this->statut = match (true) {
            $aPayer > 0 && $verse >= $aPayer => StatutCommission::PAYE,
            $verse > 0 => StatutCommission::PARTIEL,
            default => StatutCommission::IMPAYE,
        };

        $saved = $this->saveQuietly();
        $this->enveloppe->recalculStatutGlobal();

        return $saved;
    }

    public function resoudreBeneficiaire(): ?Model
    {
        return match ($this->beneficiaire_type) {
            self::TYPE_LIVREUR => Livreur::find($this->beneficiaire_id),
            self::TYPE_PROPRIETAIRE => Proprietaire::find($this->beneficiaire_id),
            self::TYPE_EMPLOYE => Employe::find($this->beneficiaire_id),
            self::TYPE_SITE => Site::find($this->beneficiaire_id),
            default => null,
        };
    }
}
