<?php

namespace App\Models;

use App\Enums\StatutFactureVente;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FactureVente extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'factures_ventes';

    protected $fillable = [
        'organization_id',
        'site_id',
        'vehicule_id',
        'commande_vente_id',
        'reference',
        'montant_brut',
        'montant_net',
        'statut_facture',
    ];

    protected $appends = ['statut_label', 'montant_encaisse', 'montant_restant'];

    protected function casts(): array
    {
        return [
            'montant_brut' => 'decimal:2',
            'montant_net' => 'decimal:2',
            'statut_facture' => StatutFactureVente::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FactureVente $f) {
            if (empty($f->reference) && $f->commande_vente_id) {
                $f->reference = CommandeVente::find($f->commande_vente_id)?->reference;
            }
            if (empty($f->statut_facture)) {
                $f->statut_facture = StatutFactureVente::CREEE;
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeVente::class, 'commande_vente_id');
    }

    public function encaissements(): HasMany
    {
        return $this->hasMany(EncaissementVente::class, 'facture_vente_id');
    }

    public function getStatutLabelAttribute(): string
    {
        return $this->statut_facture instanceof StatutFactureVente ? $this->statut_facture->label() : '';
    }

    public function getMontantEncaisseAttribute(): float
    {
        if ($this->relationLoaded('encaissements')) {
            return (float) $this->encaissements->sum('montant');
        }

        return (float) $this->encaissements()->sum('montant');
    }

    public function getMontantRestantAttribute(): float
    {
        return max(0, (float) $this->montant_net - $this->montant_encaisse);
    }

    public function isCreee(): bool
    {
        return $this->statut_facture === StatutFactureVente::CREEE;
    }

    public function isPayee(): bool
    {
        return $this->statut_facture === StatutFactureVente::PAYEE;
    }

    public function isAnnulee(): bool
    {
        return $this->statut_facture === StatutFactureVente::ANNULEE;
    }

    public function recalculStatut(): bool
    {
        if ($this->isAnnulee() || $this->isCreee()) {
            return false;
        }

        $encaisse = (float) $this->encaissements()->sum('montant');
        $net = (float) $this->montant_net;

        $this->statut_facture = match (true) {
            $encaisse <= 0 => StatutFactureVente::IMPAYEE,
            $encaisse >= $net => StatutFactureVente::PAYEE,
            default => StatutFactureVente::PARTIEL,
        };

        return $this->saveQuietly();
    }
}
