<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashbackTransaction extends Model
{
    public const TYPE_GAIN = 'gain';

    public const TYPE_VERSEMENT = 'versement';

    public const STATUT_EN_ATTENTE = 'en_attente';

    public const STATUT_VERSE = 'verse';

    protected $fillable = [
        'organization_id',
        'client_id',
        'type',
        'montant',
        'statut',
        'vente_id',
        'note',
        'verse_le',
        'verse_par',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'verse_le' => 'datetime',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeEnAttente($query)
    {
        return $query->where('statut', self::STATUT_EN_ATTENTE);
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isEnAttente(): bool
    {
        return $this->statut === self::STATUT_EN_ATTENTE;
    }

    public function isVerse(): bool
    {
        return $this->statut === self::STATUT_VERSE;
    }
}
