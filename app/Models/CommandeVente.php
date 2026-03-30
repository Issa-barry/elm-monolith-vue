<?php

namespace App\Models;

use App\Enums\StatutCommandeVente;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CommandeVente extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'commandes_ventes';

    private const TEMP_PREFIX = 'TMP-VNT-';

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
        'created_by',
        'updated_by',
    ];

    protected $appends = ['statut_label'];

    protected function casts(): array
    {
        return [
            'total_commande' => 'decimal:2',
            'statut' => StatutCommandeVente::class,
            'annulee_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CommandeVente $c) {
            if (empty($c->reference)) {
                $c->reference = self::TEMP_PREFIX.Str::uuid();
            }
            if (empty($c->statut)) {
                $c->statut = StatutCommandeVente::EN_COURS;
            }
            if (Auth::check()) {
                $c->created_by = Auth::id();
                $c->updated_by = Auth::id();
            }
        });

        static::created(function (CommandeVente $c) {
            if (! str_starts_with((string) $c->reference, self::TEMP_PREFIX)) {
                return;
            }
            $ref = 'VNT-'.($c->created_at ?? now())->format('Ymd').'-'.str_pad((string) $c->id, 4, '0', STR_PAD_LEFT);
            $c->newQueryWithoutScopes()->whereKey($c->id)->update(['reference' => $ref]);
            $c->reference = $ref;
            $c->syncOriginalAttribute('reference');
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

    // ── Méthodes métier ───────────────────────────────────────────────────────

    public function isAnnulee(): bool
    {
        return $this->statut === StatutCommandeVente::ANNULEE;
    }

    public function getMontantLabel(): string
    {
        return number_format((float) $this->total_commande, 0, ',', ' ').' GNF';
    }

    public function cloturerSiComplete(): bool
    {
        if ($this->isAnnulee()) {
            return false;
        }
        $facture = $this->facture ?? $this->load('facture')->facture;
        if (! $facture) {
            return false;
        }
        if ($facture->isPayee()) {
            $this->statut = StatutCommandeVente::CLOTUREE;

            return $this->saveQuietly();
        }

        return false;
    }
}
