<?php

namespace App\Models;

use App\Enums\StatutCommandeVente;
use App\Services\CommandeNumeroService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class CommandeVente extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'commandes_ventes';

    protected $fillable = [
        'organization_id',
        'site_id',
        'vehicule_id',
        'client_id',
        'reference',
        'total_commande',
        'statut',
        'motif_annulation',
        'annulee_at',
        'annulee_par',
        'a_charger_at',
        'chargement_demarre_at',
        'chargement_valide_at',
        'livree_at',
        'closed_at',
        'created_by',
        'updated_by',
        'numero',
    ];

    protected $appends = ['statut_label'];

    protected function casts(): array
    {
        return [
            'total_commande'        => 'decimal:2',
            'statut'                => StatutCommandeVente::class,
            'annulee_at'            => 'datetime',
            'a_charger_at'          => 'datetime',
            'chargement_demarre_at' => 'datetime',
            'chargement_valide_at'  => 'datetime',
            'livree_at'             => 'datetime',
            'closed_at'             => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CommandeVente $c) {
            if (empty($c->reference)) {
                [$c->reference, $c->numero] = app(CommandeNumeroService::class)->generer();
            }
            if (empty($c->statut)) {
                $c->statut = StatutCommandeVente::BROUILLON;
            }
            if (Auth::check()) {
                $c->created_by = Auth::id();
                $c->updated_by = Auth::id();
            }
        });

        static::updating(function (CommandeVente $c) {
            if (Auth::check()) {
                $c->updated_by = Auth::id();
            }
        });
    }

    // ── Relations ─────────────────────────────────────────────────────────────

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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(CommandeVenteLigne::class);
    }

    public function facture(): HasOne
    {
        return $this->hasOne(FactureVente::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionVente::class, 'commande_vente_id');
    }

    public function activites(): HasMany
    {
        return $this->hasMany(CommandeVenteActivite::class)->latest();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function annuleePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annulee_par');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getStatutLabelAttribute(): string
    {
        return $this->statut instanceof StatutCommandeVente ? $this->statut->label() : '';
    }

    // ── Méthodes d'état ───────────────────────────────────────────────────────

    public function isBrouillon(): bool
    {
        return $this->statut === StatutCommandeVente::BROUILLON;
    }

    public function isACharger(): bool
    {
        return $this->statut === StatutCommandeVente::A_CHARGER;
    }

    public function isChargementEnCours(): bool
    {
        return $this->statut === StatutCommandeVente::CHARGEMENT_EN_COURS;
    }

    public function isLivraisonEnCours(): bool
    {
        return $this->statut === StatutCommandeVente::LIVRAISON_EN_COURS;
    }

    public function isLivree(): bool
    {
        return $this->statut === StatutCommandeVente::LIVREE;
    }

    public function isCloturee(): bool
    {
        return $this->statut === StatutCommandeVente::CLOTUREE;
    }

    public function isAnnulee(): bool
    {
        return $this->statut === StatutCommandeVente::ANNULEE;
    }

    public function getMontantLabel(): string
    {
        return number_format((float) $this->total_commande, 0, ',', ' ').' GNF';
    }

    // ── Auto-clôture sur paiement complet ─────────────────────────────────────

    /**
     * Clôture automatiquement la commande si :
     *  - statut LIVREE
     *  - la facture est entièrement payée
     *  - toutes les commissions sont versées (ou absentes)
     */
    public function cloturerSiComplete(): bool
    {
        if (! $this->isLivree()) {
            return false;
        }

        $facture = $this->facture ?? $this->load('facture')->facture;
        $commissionsVersees = $this->commissions()->get()->every(fn ($c) => $c->isVersee());

        if (! $facture?->isPayee() || ! $commissionsVersees) {
            return false;
        }

        $this->statut   = StatutCommandeVente::CLOTUREE;
        $this->closed_at = now();

        return $this->saveQuietly();
    }
}
