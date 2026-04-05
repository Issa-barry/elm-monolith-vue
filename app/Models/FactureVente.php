<?php

namespace App\Models;

use App\Enums\StatutFactureVente;
use App\Models\CommissionPart;
use App\Services\CommissionCalculator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FactureVente extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'factures_ventes';

    private const TEMP_PREFIX = 'TMP-FAC-VNT-';

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
            if (empty($f->reference)) {
                $f->reference = self::TEMP_PREFIX.Str::uuid();
            }
            if (empty($f->statut_facture)) {
                $f->statut_facture = StatutFactureVente::IMPAYEE;
            }
        });

        static::created(function (FactureVente $f) {
            if (! str_starts_with((string) $f->reference, self::TEMP_PREFIX)) {
                return;
            }
            $ref = 'FAC-VNT-'.($f->created_at ?? now())->format('Ymd').'-'.str_pad((string) $f->id, 4, '0', STR_PAD_LEFT);
            $f->newQueryWithoutScopes()->whereKey($f->id)->update(['reference' => $ref]);
            $f->reference = $ref;
            $f->syncOriginalAttribute('reference');
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

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeVente::class, 'commande_vente_id');
    }

    public function encaissements(): HasMany
    {
        return $this->hasMany(EncaissementVente::class, 'facture_vente_id');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

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

    // ── Méthodes métier ───────────────────────────────────────────────────────

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

        $etaitPayee = $this->isPayee();

        $encaisse = (float) $this->encaissements()->sum('montant');
        $net = (float) $this->montant_net;

        if ($encaisse <= 0) {
            $this->statut_facture = StatutFactureVente::IMPAYEE;
        } elseif ($encaisse >= $net) {
            $this->statut_facture = StatutFactureVente::PAYEE;
        } else {
            $this->statut_facture = StatutFactureVente::PARTIEL;
        }

        $saved = $this->saveQuietly();

        // Générer la commission au moment où la facture devient PAYEE
        if (! $etaitPayee && $this->isPayee()) {
            $this->genererCommission();
        }

        return $saved;
    }

    private function genererCommission(): void
    {
        $this->load('commande.vehicule.equipe.membres.livreur', 'commande.vehicule.proprietaire', 'commande.lignes');
        $commande = $this->commande;

        if (! $commande || ! $commande->vehicule_id) {
            return;
        }

        // Ne pas créer en doublon
        if (CommissionVente::where('commande_vente_id', $commande->id)->exists()) {
            return;
        }

        try {
            $calc = CommissionCalculator::fromCommande($commande);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Commission non générée : '.$e->getMessage(), [
                'commande_id' => $commande->id,
                'facture_id'  => $this->id,
            ]);

            return;
        }

        $vehicule = $commande->vehicule;

        // Créer l'entête commission
        $commission = CommissionVente::create([
            'organization_id'          => $commande->organization_id,
            'commande_vente_id'        => $commande->id,
            'vehicule_id'              => $vehicule->id,
            'montant_commande'         => (float) $commande->total_commande,
            'montant_commission_totale'=> $calc['commission_totale'],
            'montant_verse'            => 0,
            'statut'                   => 'en_attente',
        ]);

        // Créer une part par bénéficiaire (membres équipe + propriétaire)
        foreach ($calc['parts'] as $part) {
            CommissionPart::create([
                'commission_vente_id'   => $commission->id,
                'type_beneficiaire'     => $part['type_beneficiaire'],
                'livreur_id'            => $part['livreur_id'],
                'proprietaire_id'       => $part['proprietaire_id'],
                'beneficiaire_nom'      => $part['beneficiaire_nom'],
                'taux_commission'       => $part['taux_commission'],
                'montant_brut'          => $part['montant_brut'],
                'frais_supplementaires' => $part['frais_supplementaires'],
                'montant_net'           => $part['montant_net'],
                'montant_verse'         => 0,
                'statut'                => 'en_attente',
            ]);
        }
    }
}
