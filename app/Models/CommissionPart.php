<?php

namespace App\Models;

use App\Enums\OrigineCommissionPart;
use App\Enums\StatutCommission;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CommissionPart extends Model
{
    use HasUlids;

    protected $table = 'commission_parts';

    protected $fillable = [
        'commission_vente_id',
        'type_beneficiaire',
        'livreur_id',
        'proprietaire_id',
        'beneficiaire_nom',
        'role',
        'taux_commission',
        'montant_brut',
        'frais_supplementaires',
        'type_frais',
        'commentaire_frais',
        'montant_net',
        'montant_verse',
        'montant_actuel',
        'origine',
        'validated_by',
        'validated_at',
        'statut',
    ];

    protected $appends = ['montant_restant', 'statut_label', 'statut_dot_class'];

    protected function casts(): array
    {
        return [
            'taux_commission' => 'decimal:2',
            'montant_brut' => 'decimal:2',
            'frais_supplementaires' => 'decimal:2',
            'montant_net' => 'decimal:2',
            'montant_verse' => 'decimal:2',
            'montant_actuel' => 'decimal:2',
            'origine' => OrigineCommissionPart::class,
            'validated_at' => 'datetime',
            'statut' => StatutCommission::class,
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function commission(): BelongsTo
    {
        return $this->belongsTo(CommissionVente::class, 'commission_vente_id');
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
        return $this->hasMany(VersementCommission::class, 'commission_part_id');
    }

    public function paiementItems(): HasMany
    {
        return $this->hasMany(PaiementCommissionVenteItem::class, 'commission_part_id');
    }

    public function adjustments(): MorphMany
    {
        return $this->morphMany(CommissionPartAdjustment::class, 'commission_part')->latest('created_at');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getMontantRestantAttribute(): float
    {
        return max(0.0, $this->montant_a_payer - (float) $this->montant_verse);
    }

    /** Montant réellement dû : ajusté par un responsable, sinon montant théorique net. */
    public function getMontantAPayerAttribute(): float
    {
        return $this->montant_actuel !== null ? (float) $this->montant_actuel : (float) $this->montant_net;
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
     * Recalcule montant_verse + statut à partir des versements réels.
     * Puis déclenche le recalcul global de la commission parente.
     */
    public function recalculStatut(): bool
    {
        $verseAncien = (float) $this->versements()->sum('montant');
        $verseNouveau = (float) $this->paiementItems()->sum('amount_allocated');
        $verse = $verseAncien + $verseNouveau;
        $aPayer = $this->montant_a_payer;

        $this->montant_verse = $verse;

        $this->statut = match (true) {
            $aPayer > 0 && $verse >= $aPayer => StatutCommission::PAYE,
            $verse > 0 => StatutCommission::PARTIEL,
            default => StatutCommission::IMPAYE,
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
