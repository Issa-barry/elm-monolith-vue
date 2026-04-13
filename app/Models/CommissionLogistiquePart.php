<?php

namespace App\Models;

use App\Enums\StatutPartCommission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class CommissionLogistiquePart extends Model
{
    protected $table = 'commission_logistique_parts';

    protected $fillable = [
        'commission_logistique_id',
        'type_beneficiaire',       // livreur | proprietaire
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
        'unlock_at',
        'periode',
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
            'statut' => StatutPartCommission::class,
            'earned_at' => 'date',
            'unlock_at' => 'date',
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

    /** Versements legacy (retro-compat — deprecated pour les nouvelles saisies). */
    public function versements(): HasMany
    {
        return $this->hasMany(VersementCommissionLogistique::class, 'commission_logistique_part_id');
    }

    /** Allocations issues des paiements groupés (nouveau système). */
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
        return $this->statut instanceof StatutPartCommission
            ? $this->statut->label()
            : '';
    }

    public function getStatutDotClassAttribute(): string
    {
        return $this->statut instanceof StatutPartCommission
            ? $this->statut->dotClass()
            : 'bg-zinc-400 dark:bg-zinc-500';
    }

    // ── État ──────────────────────────────────────────────────────────────────

    public function isVersee(): bool
    {
        return $this->statut === StatutPartCommission::PAID;
    }

    public function isAvailable(): bool
    {
        return $this->statut === StatutPartCommission::AVAILABLE
            || $this->statut === StatutPartCommission::PARTIAL;
    }

    public function isPayable(): bool
    {
        return $this->statut instanceof StatutPartCommission && $this->statut->isPayable();
    }

    public function isCancelled(): bool
    {
        return $this->statut === StatutPartCommission::CANCELLED;
    }

    /** La commission est disponible si unlock_at est passé ET qu'elle n'est pas annulée/payée. */
    public function isUnlocked(): bool
    {
        if ($this->isCancelled() || $this->isVersee()) {
            return false;
        }

        return $this->unlock_at !== null && $this->unlock_at->isPast();
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
        $verseLegacy = (float) $this->versements()->sum('montant');
        $verse = $versePayments + $verseLegacy;
        $net = (float) $this->montant_net;

        $this->montant_verse = $verse;

        if ($verse <= 0) {
            $this->statut = $this->isUnlocked()
                ? StatutPartCommission::AVAILABLE
                : StatutPartCommission::PENDING;
        } elseif ($net > 0 && $verse >= $net) {
            $this->statut = StatutPartCommission::PAID;
        } else {
            $this->statut = StatutPartCommission::PARTIAL;
        }

        $saved = $this->saveQuietly();
        $this->commission->recalculStatutGlobal();

        return $saved;
    }

    /**
     * Passe la part de PENDING → AVAILABLE si unlock_at est atteint.
     * Appelé par le job UnlockAvailableCommissionsJob.
     */
    public function tenterDeblocage(): bool
    {
        if ($this->statut !== StatutPartCommission::PENDING) {
            return false;
        }
        if (! $this->isUnlocked()) {
            return false;
        }

        $this->statut = StatutPartCommission::AVAILABLE;

        return $this->saveQuietly();
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

    // ── Calcul unlock_at ──────────────────────────────────────────────────────

    /**
     * Calcule unlock_at selon le type de bénéficiaire.
     *  - livreur       : earned_at + 14 jours
     *  - propriétaire  : 1er jour du mois suivant earned_at
     */
    public static function calculerUnlockAt(string $typeBeneficiaire, Carbon $earnedAt): Carbon
    {
        if ($typeBeneficiaire === 'livreur') {
            return $earnedAt->copy()->addDays(14);
        }

        // proprietaire : premier jour du mois suivant
        return $earnedAt->copy()->startOfMonth()->addMonth();
    }
}
