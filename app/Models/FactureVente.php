<?php

namespace App\Models;

use App\Enums\StatutFactureVente;
use App\Services\CommissionTriggerService;
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
        'montant_rembourse',
    ];

    protected $appends = ['statut_label', 'montant_encaisse', 'montant_restant'];

    protected function casts(): array
    {
        return [
            'montant_brut' => 'decimal:2',
            'montant_net' => 'decimal:2',
            'montant_rembourse' => 'decimal:2',
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

    /** Net des remboursements faits au client (ADR 0019) — identique à l'encaissé pour toute facture jamais remboursée. */
    public function getMontantRestantAttribute(): float
    {
        return max(0, (float) $this->montant_net - $this->encaisseNet());
    }

    /** Remboursements faits au client sur cette facture (ADR 0019 : trop-perçu, précommande annulée). */
    public function remboursements(): HasMany
    {
        return $this->hasMany(RemboursementVente::class, 'facture_vente_id');
    }

    /** Total remboursé, dénormalisé (`factures_ventes.montant_rembourse`), tenu à jour par PrecommandeService. */
    public function montantRembourse(): float
    {
        return round((float) $this->montant_rembourse, 2);
    }

    /** Argent du client encore détenu : encaissé (acomptes compris) moins déjà remboursé. */
    public function encaisseNet(): float
    {
        return round($this->montant_encaisse - $this->montantRembourse(), 2);
    }

    /**
     * Trop-perçu à rendre au client (ADR 0019) : ce qu'il a versé au-delà de ce qui lui est facturé
     * (quantité préparée ou remise inférieure à la précommande). Dérivé, jamais stocké ; bloque la
     * clôture tant qu'il n'est pas remboursé (cf. CommandeVente::cloturerSiComplete()).
     */
    public function tropPercu(): float
    {
        if ($this->isAnnulee()) {
            return 0.0;
        }

        return max(0.0, round($this->encaisseNet() - (float) $this->montant_net, 2));
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
        if ($this->isAnnulee()) {
            return false;
        }

        // Facture d'une précommande pas encore remise (ADR 0019) : seuls des acomptes la créditent,
        // et ils ne la font jamais passer « Payée » — sinon commission et cashback partiraient avant
        // toute remise. Elle ne quitte « Créée » qu'à son activation, à la remise.
        if ($this->isCreee() && $this->commande?->est_precommande) {
            return false;
        }

        $etaitPayee = $this->statut_facture === StatutFactureVente::PAYEE;

        $encaisse = (float) $this->encaissements()->sum('montant') - $this->montantRembourse();
        $net = (float) $this->montant_net;

        $this->statut_facture = match (true) {
            $encaisse <= 0 => StatutFactureVente::IMPAYEE,
            $encaisse >= $net => StatutFactureVente::PAYEE,
            default => StatutFactureVente::PARTIEL,
        };

        $saved = $this->saveQuietly();

        // Point unique de la transition métier réelle « facture encaissée » (jamais un
        // simple clic contrôleur) : ce point est traversé aussi bien par le contrôleur
        // que par les events du modèle EncaissementVente, donc jamais manqué. Ne se
        // déclenche que sur l'entrée dans PAYEE (pas sur PARTIEL, pas si déjà payée) —
        // cf. CommissionTriggerService::onFactureVenteEncaissee(), idempotent, sans
        // effet sous le déclencheur CHARGEMENT_VALIDE (défaut).
        if (! $etaitPayee && $this->statut_facture === StatutFactureVente::PAYEE) {
            CommissionTriggerService::onFactureVenteEncaissee($this);
        } elseif ($etaitPayee && $this->statut_facture !== StatutFactureVente::PAYEE) {
            CommissionTriggerService::onFactureVenteEncaissementRetire($this);
        }

        return $saved;
    }
}
