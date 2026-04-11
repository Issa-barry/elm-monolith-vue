<?php

namespace App\Models;

use App\Enums\StatutCommissionLogistique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected $appends = ['montant_restant', 'statut_label', 'statut_dot_class'];

    protected function casts(): array
    {
        return [
            'taux_commission'      => 'decimal:2',
            'montant_brut'         => 'decimal:2',
            'frais_supplementaires'=> 'decimal:2',
            'montant_net'          => 'decimal:2',
            'montant_verse'        => 'decimal:2',
            'statut'               => StatutCommissionLogistique::class,
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

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getMontantRestantAttribute(): float
    {
        return max(0.0, (float) $this->montant_net - (float) $this->montant_verse);
    }

    public function getStatutLabelAttribute(): string
    {
        return $this->statut instanceof StatutCommissionLogistique
            ? $this->statut->label()
            : '';
    }

    public function getStatutDotClassAttribute(): string
    {
        return $this->statut instanceof StatutCommissionLogistique
            ? $this->statut->dotClass()
            : 'bg-zinc-400 dark:bg-zinc-500';
    }

    // ── Méthodes d'état ───────────────────────────────────────────────────────

    public function isVersee(): bool
    {
        return $this->statut === StatutCommissionLogistique::VERSEE;
    }

    public function isEnAttente(): bool
    {
        return $this->statut === StatutCommissionLogistique::EN_ATTENTE;
    }

    // ── Métier ────────────────────────────────────────────────────────────────

    /**
     * Recalcule montant_verse + statut à partir des versements réels.
     * Propage ensuite au header de commission.
     * Miroir de CommissionPart::recalculStatut().
     */
    public function recalculStatut(): bool
    {
        $verse = (float) $this->versements()->sum('montant');
        $net   = (float) $this->montant_net;

        $this->montant_verse = $verse;

        if ($verse <= 0) {
            $this->statut = StatutCommissionLogistique::EN_ATTENTE;
        } elseif ($net > 0 && $verse >= $net) {
            $this->statut = StatutCommissionLogistique::VERSEE;
        } else {
            $this->statut = StatutCommissionLogistique::PARTIELLEMENT_VERSEE;
        }

        $saved = $this->saveQuietly();

        // Propager au header
        $this->commission->recalculStatutGlobal();

        return $saved;
    }

    /**
     * Applique des frais et recalcule montant_net.
     * Miroir de CommissionPart::appliquerFrais().
     */
    public function appliquerFrais(float $frais, ?string $typeFrais = null, ?string $commentaireFrais = null): bool
    {
        $this->frais_supplementaires = max(0.0, $frais);
        $this->montant_net           = max(0.0, round((float) $this->montant_brut - $this->frais_supplementaires, 2));
        $this->type_frais            = $frais > 0 ? $typeFrais : null;
        $this->commentaire_frais     = ($frais > 0 && $typeFrais === 'autre') ? $commentaireFrais : null;

        return $this->save();
    }
}
