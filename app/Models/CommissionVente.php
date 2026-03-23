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
        'livreur_id',
        'livreur_nom',
        'taux_commission',
        'montant_commande',
        'montant_commission',
        'montant_verse',
        'statut',
    ];

    protected $appends = ['montant_restant', 'statut_label'];

    protected function casts(): array
    {
        return [
            'taux_commission'    => 'decimal:2',
            'montant_commande'   => 'decimal:2',
            'montant_commission' => 'decimal:2',
            'montant_verse'      => 'decimal:2',
            'statut'             => StatutCommission::class,
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

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class);
    }

    public function versements(): HasMany
    {
        return $this->hasMany(VersementCommission::class);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getMontantRestantAttribute(): float
    {
        return max(0, (float) $this->montant_commission - (float) $this->montant_verse);
    }

    public function getStatutLabelAttribute(): string
    {
        return $this->statut instanceof StatutCommission ? $this->statut->label() : '';
    }

    // ── Méthodes métier ───────────────────────────────────────────────────────

    public function recalculStatut(): bool
    {
        if ($this->statut === StatutCommission::ANNULEE) {
            return false;
        }

        $verse = (float) $this->versements()->sum('montant');
        $total = (float) $this->montant_commission;

        $this->montant_verse = $verse;

        if ($verse <= 0) {
            $this->statut = StatutCommission::EN_ATTENTE;
        } elseif ($verse >= $total) {
            $this->statut = StatutCommission::VERSEE;
        } else {
            $this->statut = StatutCommission::PARTIELLE;
        }

        return $this->saveQuietly();
    }

    public function isVersee(): bool
    {
        return $this->statut === StatutCommission::VERSEE;
    }

    public function isAnnulee(): bool
    {
        return $this->statut === StatutCommission::ANNULEE;
    }
}
