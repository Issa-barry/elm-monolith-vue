<?php

namespace App\Models;

use App\Enums\StatutCommandeAchat;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CommandeAchat extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'commandes_achats';

    private const TEMP_PREFIX = 'TMP-ACH-';

    protected $fillable = [
        'organization_id',
        'prestataire_id',
        'reference',
        'note',
        'total_commande',
        'statut',
        'motif_annulation',
        'annulee_at',
        'annulee_par',
        'created_by',
        'updated_by',
    ];

    protected $appends = ['statut_label'];

    protected function casts(): array
    {
        return [
            'total_commande' => 'decimal:2',
            'statut' => StatutCommandeAchat::class,
            'annulee_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CommandeAchat $c) {
            if (empty($c->reference)) {
                $c->reference = self::TEMP_PREFIX.Str::uuid();
            }
            if (empty($c->statut)) {
                $c->statut = StatutCommandeAchat::EN_COURS;
            }
            if (Auth::check()) {
                $c->created_by = Auth::id();
                $c->updated_by = Auth::id();
            }
        });

        static::created(function (CommandeAchat $c) {
            if (! str_starts_with((string) $c->reference, self::TEMP_PREFIX)) {
                return;
            }
            $ref = 'ACH-'.($c->created_at ?? now())->format('Ymd').'-'.str_pad((string) $c->id, 4, '0', STR_PAD_LEFT);
            $c->newQueryWithoutScopes()->whereKey($c->id)->update(['reference' => $ref]);
            $c->reference = $ref;
            $c->syncOriginalAttribute('reference');
        });

        static::updating(function (CommandeAchat $c) {
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

    public function prestataire(): BelongsTo
    {
        return $this->belongsTo(Prestataire::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(CommandeAchatLigne::class);
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
        return $this->statut instanceof StatutCommandeAchat ? $this->statut->label() : '';
    }

    // ── Méthodes métier ───────────────────────────────────────────────────────

    public function isAnnulee(): bool
    {
        return $this->statut === StatutCommandeAchat::ANNULEE;
    }

    public function isReceptionnee(): bool
    {
        return $this->statut === StatutCommandeAchat::RECEPTIONNEE;
    }
}
