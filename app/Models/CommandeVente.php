<?php

namespace App\Models;

use App\Enums\CommissionGenerationStatut;
use App\Enums\ModeTarification;
use App\Enums\StatutCommandeVente;
use App\Services\CommandeNumeroService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
        'client_vehicule_id',
        'reference',
        'total_commande',
        'mode_tarification_snapshot',
        'commission_eligible_snapshot',
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
            'total_commande' => 'decimal:2',
            'mode_tarification_snapshot' => ModeTarification::class,
            'commission_eligible_snapshot' => 'boolean',
            'statut' => StatutCommandeVente::class,
            'annulee_at' => 'datetime',
            'a_charger_at' => 'datetime',
            'chargement_demarre_at' => 'datetime',
            'chargement_valide_at' => 'datetime',
            'livree_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function clientVehicule(): BelongsTo
    {
        return $this->belongsTo(ClientVehicle::class, 'client_vehicule_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(CommandeVenteLigne::class);
    }

    public function facture(): HasOne
    {
        return $this->hasOne(FactureVente::class);
    }

    public function commissions(): MorphMany
    {
        return $this->morphMany(CommissionEnveloppe::class, 'source');
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

    public function isFacturation(): bool
    {
        return $this->statut === StatutCommandeVente::FACTURATION;
    }

    public function isCloturee(): bool
    {
        return $this->statut === StatutCommandeVente::CLOTUREE;
    }

    public function isAnnulee(): bool
    {
        return $this->statut === StatutCommandeVente::ANNULEE;
    }

    public function isEncaissable(): bool
    {
        return in_array($this->statut, [
            StatutCommandeVente::LIVRAISON_EN_COURS,
            StatutCommandeVente::LIVREE,
            StatutCommandeVente::FACTURATION,
            StatutCommandeVente::CLOTUREE,
        ], true);
    }

    public function getMontantLabel(): string
    {
        return number_format((float) $this->total_commande, 0, ',', ' ').' GNF';
    }

    // ── Auto-clôture sur paiement complet ─────────────────────────────────────

    /**
     * Clôture automatiquement la commande si :
     *  - statut LIVREE (workflow logistique) ou FACTURATION (vente directe)
     *  - la facture est entièrement payée
     *  - les commissions dues sont soit inexistantes de façon légitime
     *    (véhicule non éligible), soit générées avec succès ET versées
     *
     * Ne clôture JAMAIS silencieusement une commande éligible dont la
     * génération de commission a échoué ou n'a pas encore été tentée — cf.
     * incident CMD-230826-004 : une collection de commissions vide suite à un
     * échec de génération était auparavant traitée comme "tout payé" par
     * vacuité logique (Collection::every() sur une collection vide renvoie
     * toujours true).
     */
    public function cloturerSiComplete(): bool
    {
        if (! $this->isLivree() && ! $this->isFacturation()) {
            return false;
        }

        $facture = $this->load('facture')->facture;
        if (! $facture?->isPayee() || ! $this->commissionsPretesPourCloture()) {
            return false;
        }

        $this->statut = StatutCommandeVente::CLOTUREE;
        $this->closed_at = now();

        return $this->saveQuietly();
    }

    private function commissionsPretesPourCloture(): bool
    {
        if (! $this->commission_eligible_snapshot) {
            // Véhicule non éligible aux commissions : rien n'est dû, clôture légitime.
            return true;
        }

        $processusId = CommissionProcessus::where('organization_id', $this->organization_id)
            ->where('code', CommissionProcessus::CODE_VENTE)
            ->value('id');

        $statutGeneration = $processusId
            ? CommissionGenerationAttempt::statutCourant(self::class, $this->id, $processusId)
            : null;

        // ERREUR (à régulariser) ou aucune tentative alors que la commande est éligible
        // et a déjà atteint son déclencheur (facture payée / chargement validé, déjà
        // garanti par les gardes ci-dessus) : jamais de clôture silencieuse, on attend
        // une tentative réussie.
        if ($statutGeneration !== CommissionGenerationStatut::SUCCES) {
            return false;
        }

        return $this->commissions()->get()->every(fn (CommissionEnveloppe $c) => $c->isPaye());
    }
}
