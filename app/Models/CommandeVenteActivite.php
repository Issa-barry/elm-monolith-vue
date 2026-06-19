<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommandeVenteActivite extends Model
{
    use HasUlids;

    protected $table = 'commande_vente_activites';

    protected $fillable = [
        'commande_vente_id',
        'user_id',
        'action',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeVente::class, 'commande_vente_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static array $labels = [
        'creation' => 'a créé la commande',
        'creation_confirmee' => 'a créé et confirmé la commande',
        'confirmee' => 'a confirmé la commande',
        'chargement_demarre' => 'a démarré le chargement',
        'chargement_valide' => 'a validé le chargement',
        'livraison_demarree' => 'a démarré la livraison',
        'livree' => 'a marqué la commande comme livrée',
        'cloturee' => 'a clôturé la commande',
        'annulee' => 'a annulé la commande',
        'encaissement_recu' => 'a enregistré un encaissement',
        'commission_generee' => 'a généré la commission',
        'versement_effectue' => 'a effectué un versement',
    ];

    public function getActionLabelAttribute(): string
    {
        return self::$labels[$this->action] ?? $this->action;
    }
}
