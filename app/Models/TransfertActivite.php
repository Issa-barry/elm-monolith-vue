<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransfertActivite extends Model
{
    protected $table = 'transfert_activites';

    protected $fillable = [
        'transfert_logistique_id',
        'user_id',
        'action',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function transfert(): BelongsTo
    {
        return $this->belongsTo(TransfertLogistique::class, 'transfert_logistique_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static array $labels = [
        'creation'            => 'a créé le transfert',
        'chargement_demarre'  => 'a démarré le chargement',
        'chargement_valide'   => 'a validé le chargement',
        'reception_validee'   => 'a validé la réception',
        'cloture'             => 'a clôturé le transfert',
        'annule'              => 'a annulé le transfert',
        'commission_generee'  => 'a généré la commission logistique',
        'versement_effectue'  => 'a effectué un versement',
    ];

    public function getActionLabelAttribute(): string
    {
        return self::$labels[$this->action] ?? $this->action;
    }
}
