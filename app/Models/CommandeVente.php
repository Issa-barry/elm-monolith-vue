<?php

namespace App\Models;

use App\Enums\CommissionGenerationStatut;
use App\Enums\ModeRemiseGrossiste;
use App\Enums\ModeTarification;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Services\ReferenceNumeroService;
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

    public const STATUT_AFFICHAGE_COMMISSIONS_A_VERSER = 'commissions_a_verser';

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
        'nature_operation',
        'mode_remise_grossiste',
        'statut',
        'motif_annulation',
        'annulee_at',
        'annulee_par',
        'a_charger_at',
        'chargement_demarre_at',
        'chargement_valide_at',
        'livree_at',
        'reception_validee_at',
        'validated_at',
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
            'nature_operation' => NatureOperation::class,
            'mode_remise_grossiste' => ModeRemiseGrossiste::class,
            'statut' => StatutCommandeVente::class,
            'annulee_at' => 'datetime',
            'a_charger_at' => 'datetime',
            'chargement_demarre_at' => 'datetime',
            'chargement_valide_at' => 'datetime',
            'livree_at' => 'datetime',
            'reception_validee_at' => 'datetime',
            'validated_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CommandeVente $c) {
            if (empty($c->reference)) {
                // Repli sur VENTE_STANDARD (même valeur que le défaut colonne) si nature_operation
                // n'a pas été renseigné avant la création — jamais un null pointer ici : les deux
                // points d'entrée réels (Ventes\StoreCommandeVenteController, PdvCheckoutService::
                // checkout()) le renseignent toujours explicitement, ce repli ne sert qu'aux
                // créations directes (tests, scripts) qui s'en remettent au défaut colonne.
                $prefixe = ($c->nature_operation ?? NatureOperation::VENTE_STANDARD)->prefixeReference();
                [$c->reference, $c->numero] = app(ReferenceNumeroService::class)->generer($c->organization_id, $prefixe);
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

    public function retours(): HasMany
    {
        return $this->hasMany(CommandeVenteRetour::class)->latest();
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

    /**
     * Statut tel qu'affiché à l'écran (fiche, liste, export web) ; `statut` reste la valeur brute
     * du workflow et `statut_label` (API, mobile) n'est pas modifié.
     *
     * FACTURATION ne veut dire « À encaisser » que tant que la facture n'est pas soldée : une fois
     * PAYEE, la commande n'attend plus que le versement des commissions avant l'auto-clôture
     * (cf. cloturerSiComplete()). Afficher « À encaisser » à côté d'une facture « Payée » serait
     * contradictoire.
     *
     * @return array{value: string|null, label: string}
     */
    public function statutAffichage(): array
    {
        if ($this->isFacturation() && $this->facture?->isPayee()) {
            return [
                'value' => self::STATUT_AFFICHAGE_COMMISSIONS_A_VERSER,
                'label' => 'Commissions à verser',
            ];
        }

        return ['value' => $this->statut?->value, 'label' => $this->statut_label];
    }

    /**
     * Total des unités de la commande (somme des CommandeVenteLigne::quantite_effective). Charger
     * `lignes` en amont pour les listes : sans cela, chaque commande déclenche sa propre requête.
     */
    public function getQuantiteTotaleAttribute(): int
    {
        return (int) $this->lignes->sum(fn (CommandeVenteLigne $l) => $l->quantite_effective);
    }

    // ── Méthodes d'état ───────────────────────────────────────────────────────

    public function isBrouillon(): bool
    {
        return $this->statut === StatutCommandeVente::BROUILLON;
    }

    /** Source de vérité unique pour « cette commande peut être modifiée » — cf. StatutCommandeVente::isEditable() */
    public function isEditable(): bool
    {
        return $this->statut->isEditable();
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

    /**
     * Source de vérité unique de « cette commande a besoin d'une validation de réception
     * explicite avant de passer en LIVREE » — jusqu'au 06/09/2026 réservé à distribution_client
     * (cf. docs/commissions.md COMM-004) ; étendu ce jour-là à Grossiste + Livraison (cf.
     * docs/grossiste.md, chantier « Réception Grossiste ») : une livraison Grossiste doit elle
     * aussi être réceptionnée par le client avant clôture, la facture au Grossiste restant
     * indépendante de la réception (montant recalculé sur le réceptionné, jamais au-delà). Une
     * vente standard sans véhicule (Enlèvement, Externe/Revendeur…) n'a jamais de véhicule à
     * réceptionner et reste donc toujours false.
     */
    public function requiertReceptionExplicite(): bool
    {
        return $this->nature_operation === NatureOperation::DISTRIBUTION_CLIENT
            || $this->mode_remise_grossiste === ModeRemiseGrossiste::LIVRAISON;
    }

    public function isAnnulee(): bool
    {
        return $this->statut === StatutCommandeVente::ANNULEE;
    }

    public function isRetournee(): bool
    {
        return $this->statut === StatutCommandeVente::RETOURNEE;
    }

    /**
     * Annulée exceptionnellement pour erreur de saisie (cf. AnnulationExceptionnelleService).
     * Volontairement distinct d'isAnnulee() : une telle commande n'est jamais supprimable
     * (DestroyCommandeVenteController), sa trace doit rester consultable.
     */
    public function isAnnuleeErreurSaisie(): bool
    {
        return $this->statut === StatutCommandeVente::ANNULEE_ERREUR_SAISIE;
    }

    public function annulationExceptionnelle(): HasOne
    {
        return $this->hasOne(AnnulationExceptionnelle::class, 'commande_vente_id');
    }

    /**
     * Source de vérité unique de « un retour de livraison peut être enregistré maintenant » (cf.
     * CommandeVenteRetourService) : renvoie la raison du refus, ou null si le retour est possible.
     * Il l'est tant que la marchandise est en livraison ET que rien n'a été encaissé — le premier
     * encaissement d'une vente standard fait passer la commande en LIVREE (cf.
     * CommandeVenteService::passerEnLivree()), mais le montant encaissé est aussi contrôlé
     * directement, un encaissement pouvant être créé sans passer par ce chemin (import, API).
     * Réservé aux ventes sans réception explicite : une commande à réception explicite (distribution,
     * Grossiste livré) constate déjà ce que le client a accepté via l'écart de réception (cf.
     * CommandeVenteService::validerReception()), avec ses propres règles de stock et de commission.
     */
    public function raisonRetourImpossible(): ?string
    {
        if (! $this->isLivraisonEnCours()) {
            return 'Un retour ne peut être enregistré que pendant la livraison, avant tout encaissement.';
        }

        if ($this->requiertReceptionExplicite()) {
            return 'Cette commande constate ce que le client a accepté à la validation de réception : utilisez l\'écart de réception, pas un retour.';
        }

        $this->loadMissing('lignes', 'facture');

        if ($this->facture && ($this->facture->isAnnulee() || (float) $this->facture->montant_encaisse > 0)) {
            return 'Un retour n\'est plus possible : la facture est annulée ou a déjà reçu un encaissement.';
        }

        if ($this->lignes->every(fn (CommandeVenteLigne $l) => $l->quantite_retournable <= 0)) {
            return 'Toute la marchandise chargée a déjà été retournée.';
        }

        return null;
    }

    public function isRetournable(): bool
    {
        return $this->raisonRetourImpossible() === null;
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
        // Grossiste + Enlèvement (pas de véhicule) reste commission_eligible_snapshot=false —
        // cette valeur ne reflète que l'éligibilité véhicule (propriétaire/équipe), jamais celle
        // du consultant/site qui en sont indépendants (cf. CommissionEnveloppeGenerator). Ne
        // conclure "rien n'est dû" que si AUCUNE enveloppe n'a effectivement été générée, sinon
        // une commission consultant légitime pourrait être clôturée sans avoir été vérifiée/payée.
        if (! $this->commission_eligible_snapshot && ! $this->commissions()->exists()) {
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
