<?php

namespace App\Models;

use App\Enums\StatutTransfert;
use App\Services\ReferenceNumeroService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class TransfertLogistique extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'transferts_logistiques';

    /** Décision produit du 31/08/2026 : remplace l'ancien format TR-NNNNN-XXX. */
    private const REFERENCE_PREFIX = 'TRF';

    private const CODE_CHARSET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'organization_id',
        'reference',
        'code_confirmation',
        'site_source_id',
        'site_destination_id',
        'vehicule_id',
        'equipe_livraison_id',
        'statut',
        'date_depart_prevue',
        'date_depart_reelle',
        'date_arrivee_prevue',
        'date_arrivee_reelle',
        'notes',
        'created_by',
        'validation_reception',
        'validated_by',
        'validated_at',
        'validation_motif',
        'numero',
    ];

    protected $appends = ['statut_label'];

    protected function casts(): array
    {
        return [
            'statut' => StatutTransfert::class,
            'date_depart_prevue' => 'date',
            'date_depart_reelle' => 'date',
            'date_arrivee_prevue' => 'date',
            'date_arrivee_reelle' => 'date',
            'validated_at' => 'datetime',
        ];
    }

    private static function generateConfirmationCode(): string
    {
        $charset = self::CODE_CHARSET;
        $len = strlen($charset);

        return $charset[random_int(0, $len - 1)]
            .$charset[random_int(0, $len - 1)]
            .$charset[random_int(0, $len - 1)];
    }

    protected static function booted(): void
    {
        static::creating(function (TransfertLogistique $t) {
            if (empty($t->reference)) {
                // Remplace l'ancien MAX(numero)+1 non verrouillé (course possible entre deux
                // workers) par une séquence journalière verrouillée, scopée par organisation —
                // même générateur que CommandeVente (VTE-/DST-), cf. ReferenceNumeroService.
                // code_confirmation reste un code de confirmation distinct (affiché au chauffeur),
                // il n'entre plus dans la référence elle-même.
                [$t->reference, $t->numero] = app(ReferenceNumeroService::class)->generer($t->organization_id, self::REFERENCE_PREFIX);
                $t->code_confirmation = self::generateConfirmationCode();
            }
            if (empty($t->statut)) {
                $t->statut = StatutTransfert::BROUILLON;
            }
            if (Auth::check()) {
                $t->created_by = Auth::id();
            }
        });
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function siteSource(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_source_id');
    }

    public function siteDestination(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_destination_id');
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);
    }

    public function equipeLivraison(): BelongsTo
    {
        return $this->belongsTo(EquipeLivraison::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(TransfertLigne::class);
    }

    /** @deprecated commission legacy (moteur CommissionLogistiqueService) — historique en lecture seule */
    public function commission(): HasOne
    {
        return $this->hasOne(CommissionLogistique::class);
    }

    /** Commissions générées par le moteur générique (CommissionEnveloppeGenerator), organisations migrées uniquement. */
    public function commissions(): MorphMany
    {
        return $this->morphMany(CommissionEnveloppe::class, 'source');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function activites(): HasMany
    {
        return $this->hasMany(TransfertActivite::class)->orderBy('created_at');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getStatutLabelAttribute(): string
    {
        return $this->statut instanceof StatutTransfert ? $this->statut->label() : '';
    }

    // ── Méthodes d'état ───────────────────────────────────────────────────────

    public function isBrouillon(): bool
    {
        return $this->statut === StatutTransfert::BROUILLON;
    }

    public function isEditable(): bool
    {
        return $this->statut instanceof StatutTransfert && $this->statut->isEditable();
    }

    public function isTransit(): bool
    {
        return $this->statut === StatutTransfert::TRANSIT;
    }

    public function isReception(): bool
    {
        return $this->statut === StatutTransfert::RECEPTION;
    }

    public function isCloture(): bool
    {
        return $this->statut === StatutTransfert::CLOTURE;
    }

    public function isAnnule(): bool
    {
        return $this->statut === StatutTransfert::ANNULE;
    }

    public function isTerminal(): bool
    {
        return $this->statut instanceof StatutTransfert && $this->statut->isTerminal();
    }

    public function isValideeAdmin(): bool
    {
        return $this->validation_reception === 'accord';
    }

    public function isRefuseeAdmin(): bool
    {
        return $this->validation_reception === 'refus';
    }

    public function hasValidationAdmin(): bool
    {
        return $this->validation_reception !== null;
    }
}
