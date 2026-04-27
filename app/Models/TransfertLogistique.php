<?php

namespace App\Models;

use App\Enums\StatutTransfert;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransfertLogistique extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'transferts_logistiques';

    private const TEMP_PREFIX = 'TMP-TR-';

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
                $t->numero = (DB::table('transferts_logistiques')->max('numero') ?? 0) + 1;
                $t->code_confirmation = self::generateConfirmationCode();
                $t->reference = self::TEMP_PREFIX.bin2hex(random_bytes(6));
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
            $code = $t->code_confirmation ?? self::generateConfirmationCode();
            $ref = 'TR-'.str_pad((string) $t->numero, 5, '0', STR_PAD_LEFT).'-'.$code;
            $t->newQueryWithoutScopes()->whereKey($t->id)->update(['reference' => $ref, 'code_confirmation' => $code]);
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
