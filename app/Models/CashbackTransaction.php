<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashbackTransaction extends Model
{
    use HasUlids;

    public const TYPE_GAIN = 'gain';

    public const TYPE_VERSEMENT = 'versement';

    public const STATUT_EN_ATTENTE = 'en_attente';

    public const STATUT_VALIDE = 'valide';

    public const STATUT_PARTIEL = 'partiel';

    public const STATUT_VERSE = 'verse';

    protected $fillable = [
        'organization_id',
        'client_id',
        'type',
        'montant',
        'montant_verse',
        'statut',
        'vente_id',
        'note',
        'verse_le',
        'verse_par',
        'valide_le',
        'valide_par',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'montant_verse' => 'integer',
            'verse_le' => 'datetime',
            'valide_le' => 'datetime',
        ];
    }

    // ── Accesseurs ────────────────────────────────────────────────────────────

    public function getMontantRestantAttribute(): int
    {
        return max(0, $this->montant - $this->montant_verse);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeEnAttente($query)
    {
        return $query->where('statut', self::STATUT_EN_ATTENTE);
    }

    public function scopeValide($query)
    {
        return $query->where('statut', self::STATUT_VALIDE);
    }

    public function scopePartiel($query)
    {
        return $query->where('statut', self::STATUT_PARTIEL);
    }

    public function scopeVerse($query)
    {
        return $query->where('statut', self::STATUT_VERSE);
    }

    public function scopeGains($query)
    {
        return $query->where('type', self::TYPE_GAIN);
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function vente(): BelongsTo
    {
        return $this->belongsTo(CommandeVente::class, 'vente_id');
    }

    public function versePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verse_par');
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    public function versements(): HasMany
    {
        return $this->hasMany(CashbackVersement::class, 'cashback_transaction_id')->orderBy('date_versement');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isEnAttente(): bool
    {
        return $this->statut === self::STATUT_EN_ATTENTE;
    }

    public function isValide(): bool
    {
        return $this->statut === self::STATUT_VALIDE;
    }

    public function isPartiel(): bool
    {
        return $this->statut === self::STATUT_PARTIEL;
    }

    public function isVerse(): bool
    {
        return $this->statut === self::STATUT_VERSE;
    }

    /** Vrai si le versement (partiel ou total) est possible */
    public function isVersable(): bool
    {
        return in_array($this->statut, [self::STATUT_VALIDE, self::STATUT_PARTIEL], true);
    }

    /** Recalcule le statut après un versement */
    public function recalculStatut(): void
    {
        $total = (int) $this->versements()->sum('montant');
        $this->montant_verse = $total;

        if ($total <= 0) {
            $this->statut = self::STATUT_VALIDE;
        } elseif ($total >= $this->montant) {
            $this->statut = self::STATUT_VERSE;
            $this->verse_le = now();
        } else {
            $this->statut = self::STATUT_PARTIEL;
        }

        $this->saveQuietly();
    }
}
