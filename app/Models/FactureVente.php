<?php

namespace App\Models;

use App\Enums\StatutFactureVente;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class FactureVente extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'factures_ventes';

    private const TEMP_PREFIX = 'TMP-FA-';

    private const CODE_CHARSET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'organization_id',
        'site_id',
        'vehicule_id',
        'commande_vente_id',
        'reference',
        'code_confirmation',
        'montant_brut',
        'montant_net',
        'statut_facture',
        'numero',
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
        static::creating(function (FactureVente $f) {
            if (empty($f->reference)) {
                $f->numero = (DB::table('factures_ventes')->max('numero') ?? 0) + 1;
                $f->code_confirmation = self::generateConfirmationCode();
                $f->reference = self::TEMP_PREFIX.bin2hex(random_bytes(6));
            }
            if (empty($f->statut_facture)) {
                $f->statut_facture = StatutFactureVente::IMPAYEE;
            }
        });

        static::created(function (FactureVente $f) {
            if (! str_starts_with((string) $f->reference, self::TEMP_PREFIX)) {
                return;
            }
            $code = $f->code_confirmation ?? self::generateConfirmationCode();
            $ref = 'FA-'.str_pad((string) $f->numero, 5, '0', STR_PAD_LEFT).'-'.$code;
            $f->newQueryWithoutScopes()->whereKey($f->id)->update(['reference' => $ref, 'code_confirmation' => $code]);
            $f->reference = $ref;
            $f->syncOriginalAttribute('reference');
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

    public function getMontantRestantAttribute(): float
    {
        return max(0, (float) $this->montant_net - $this->montant_encaisse);
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

        return $this->saveQuietly();
    }
}
