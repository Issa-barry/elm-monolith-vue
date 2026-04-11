<?php

namespace App\Models;

use App\Enums\StatutTransfert;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class TransfertLogistique extends Model
{
    use SoftDeletes;

    protected $table = 'transferts_logistiques';

    private const TEMP_PREFIX = 'TMP-TRF-';

    protected $fillable = [
        'organization_id',
        'reference',
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
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TransfertLogistique $t) {
            if (empty($t->reference)) {
                $t->reference = self::TEMP_PREFIX.Str::uuid();
            }
            if (empty($t->statut)) {
                $t->statut = StatutTransfert::BROUILLON;
            }
            if (Auth::check()) {
                $t->created_by = Auth::id();
            }
        });

        static::created(function (TransfertLogistique $t) {
            if (! str_starts_with((string) $t->reference, self::TEMP_PREFIX)) {
                return;
            }
            $ref = 'TRF-'.($t->created_at ?? now())->format('Ymd').'-'.str_pad((string) $t->id, 4, '0', STR_PAD_LEFT);
            $t->newQueryWithoutScopes()->whereKey($t->id)->update(['reference' => $ref]);
            $t->reference = $ref;
            $t->syncOriginalAttribute('reference');
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

    public function commission(): HasOne
    {
        return $this->hasOne(CommissionLogistique::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
}
